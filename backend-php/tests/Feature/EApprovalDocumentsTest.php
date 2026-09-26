<?php

namespace Tests\Feature;

use App\Models\ApprovalAuthority;
use App\Models\ApprovalAuthorityMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\PurchaseOrder;
use App\Models\SystemMailSetting;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Mailer;
use App\Services\PasswordPolicy;
use App\Services\ServiceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog 2, eApproval (Dennis, 2026-09-26, decision page): Payment
 * Vouchers above a Bank Authority's amount wait for its signatories
 * before banking; Purchase Orders above the supplier's limit go to the
 * eApproval approvers; Service Records are approved or rejected (with a
 * reason) by the Service Record Approval authority; approvers are
 * emailed as each item arrives.
 */
class EApprovalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        Mailer::fake();
    }

    /** The system mailbox -- set up after everyone has signed in, since with it sign-in asks for an OTP. */
    private function mailbox(): void
    {
        SystemMailSetting::create([
            'purpose' => 'otp', 'host' => 'smtp.example.test', 'port' => 587, 'username' => 'erp@webmaster.example',
            'password' => 'pw', 'use_tls' => true, 'from_email' => 'erp@webmaster.example', 'from_name' => 'Websoft ERP',
        ]);
    }

    protected function tearDown(): void
    {
        Mailer::restore();
        parent::tearDown();
    }

    /** A user with the given modules at FULL, signed in. @return array{0: User, 1: array<string,string>} */
    private function login(string $role, array $modules = ['accounts_payable', 'service_records', 'service_operations']): array
    {
        $group = Group::factory()->for($this->company)->create();
        foreach ($modules as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $key], ['enabled' => true]);
            GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => $key, 'access_level' => GroupModuleAuthority::FULL]);
        }
        $user = User::factory()->for($this->company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    /** @param array<int, User> $members */
    private function authority(string $name, array $members, string $entityType, ?float $threshold = null, ?string $bankAccountId = null, string $mode = 'any_one'): ApprovalAuthority
    {
        $a = ApprovalAuthority::create(['company_id' => $this->company->id, 'name' => $name, 'mode' => $mode, 'bank_account_id' => $bankAccountId]);
        foreach ($members as $m) {
            ApprovalAuthorityMember::create(['authority_id' => $a->id, 'user_id' => $m->id]);
        }
        ApprovalRule::create(['authority_id' => $a->id, 'entity_type' => $entityType, 'threshold_amount' => $threshold]);

        return $a;
    }

    private function pv(array $h, BankAccount $bank, float $amount): array
    {
        $supplier = CompanyIndividual::factory()->for($this->company)->create(['is_supplier' => true]);

        return $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => $amount, 'bank_account_id' => $bank->id,
        ], $h)->assertOk()->json();
    }

    public function test_a_payment_voucher_above_its_bank_authoritys_amount_waits_for_the_signatories_before_banking(): void
    {
        [, $h] = $this->login(User::ROLE_FINANCE);
        [$signatory, $sh] = $this->login(User::ROLE_OWNER);
        $dbs = BankAccount::factory()->for($this->company)->create();
        $ocbc = BankAccount::factory()->for($this->company)->create();
        $this->authority('DBS signatories', [$signatory], 'payment_voucher', 5000, $dbs->id);
        $this->mailbox();

        $small = $this->pv($h, $dbs, 1000);
        $this->assertNull($small['approval_status'], 'below the amount it goes straight');
        $this->postJson("/api/accounts-payable/payments/{$small['id']}/bank", [], $h)->assertOk();

        $other = $this->pv($h, $ocbc, 9000);
        $this->assertNull($other['approval_status'], 'another account\'s authority does not apply');

        $big = $this->pv($h, $dbs, 9000);
        $this->assertSame('pending', $big['approval_status']);
        $this->assertStringContainsString('DBS signatories', $big['approval_note']);
        $this->postJson("/api/accounts-payable/payments/{$big['id']}/bank", [], $h)->assertStatus(422);
        $this->assertCount(1, Mailer::sent(), 'the signatory is emailed as it arrives');
        $this->assertStringContainsString($big['voucher_number'], Mailer::sent()[0]['subject']);

        $request = ApprovalRequest::where('entity_id', $big['id'])->sole();
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => 'approved'], $sh)->assertOk();
        $this->postJson("/api/accounts-payable/payments/{$big['id']}/bank", [], $h)->assertOk();
    }

    public function test_a_rejected_payment_voucher_cannot_be_banked(): void
    {
        [, $h] = $this->login(User::ROLE_FINANCE);
        [$signatory, $sh] = $this->login(User::ROLE_OWNER);
        $bank = BankAccount::factory()->for($this->company)->create();
        $this->authority('All accounts', [$signatory], 'payment_voucher', 100);
        $pv = $this->pv($h, $bank, 500);
        $request = ApprovalRequest::where('entity_id', $pv['id'])->sole();
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => 'rejected', 'comment' => 'Wrong supplier'], $sh)->assertOk();
        $this->postJson("/api/accounts-payable/payments/{$pv['id']}/bank", [], $h)
            ->assertStatus(422)->assertJsonFragment(['detail' => "{$pv['voucher_number']} cannot be banked yet: Rejected by {$signatory->full_name}: Wrong supplier."]);
    }

    public function test_a_po_above_the_suppliers_limit_is_decided_by_the_eapproval_approvers(): void
    {
        [, $h] = $this->login(User::ROLE_FINANCE);
        [$approver, $ah] = $this->login(User::ROLE_SALES_MANAGER);
        $this->authority('PO approvers', [$approver], 'purchase_order');
        $supplier = CompanyIndividual::factory()->for($this->company)->create(['is_supplier' => true, 'po_approval_limit_sgd' => 1000]);
        $raise = fn (float $amount) => $this->postJson('/api/accounts-payable/purchase-orders', [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(), 'description' => 'Parts', 'amount_sgd' => $amount,
        ], $h)->assertOk()->json();

        $within = $raise(500);
        $this->assertSame('draft', $within['status']);
        $this->assertNull($within['approval_status'], 'within the limit: no approval request');

        $above = $raise(5000);
        $this->assertSame('pending_approval', $above['status']);
        $this->assertSame('pending', $above['approval_status']);
        $this->postJson("/api/accounts-payable/purchase-orders/{$above['id']}/approve", [], $h)->assertStatus(422);

        $request = ApprovalRequest::where('entity_id', $above['id'])->sole();
        $this->assertStringContainsString($above['po_number'], $request->summary);
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => 'approved'], $ah)->assertOk();
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, PurchaseOrder::find($above['id'])->status);

        $rejected = $raise(7000);
        $request = ApprovalRequest::where('entity_id', $rejected['id'])->sole();
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => 'rejected', 'comment' => 'Over budget'], $ah)->assertOk();
        $po = PurchaseOrder::find($rejected['id']);
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->status);
        $this->assertSame('Rejected in eApproval: Over budget', $po->cancel_reason);
    }

    public function test_with_no_po_authority_set_up_the_owner_approves_above_the_limit_as_before(): void
    {
        [, $h] = $this->login(User::ROLE_OWNER);
        $supplier = CompanyIndividual::factory()->for($this->company)->create(['is_supplier' => true, 'po_approval_limit_sgd' => 1000]);
        $po = $this->postJson('/api/accounts-payable/purchase-orders', [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(), 'description' => 'Parts', 'amount_sgd' => 5000,
        ], $h)->assertOk()->json();
        $this->assertNull($po['approval_status']);
        $this->postJson("/api/accounts-payable/purchase-orders/{$po['id']}/approve", [], $h)->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_service_records_are_approved_or_rejected_with_a_reason_by_the_authority_members(): void
    {
        [$nico, $nh] = $this->login(User::ROLE_SUPPORT_ENGINEER);
        [, $lh] = $this->login(User::ROLE_SERVICE_LEAD); // a Service Lead outside the authority
        $this->authority('Service Record Approval', [$nico], 'service_record');
        [, $eh] = $this->login(User::ROLE_SUPPORT_ENGINEER);
        $this->mailbox();
        $customer = CompanyIndividual::factory()->for($this->company)->create();
        $contract = Contract::factory()->for($this->company)->create(['customer_id' => $customer->id, 'contracted_minutes' => 600, 'status' => Contract::STATUS_ACTIVE]);
        $jo = JobOrder::factory()->for($this->company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);
        $engineer = User::factory()->for($this->company)->create();
        $log = fn () => $this->postJson('/api/service-records', [
            'job_order_id' => $jo->id, 'employee_user_id' => $engineer->id, 'work_date' => now()->toDateString(), 'raw_minutes' => 60,
        ], $eh)->assertOk()->json('id');

        $first = $log();
        $this->assertSame('pending', ApprovalRequest::where('entity_id', $first)->value('status'));
        $this->assertNotEmpty(Mailer::sent(), 'the approver is emailed as it arrives');

        $this->postJson("/api/service-records/{$first}/approve", ['deducted_minutes' => 60], $lh)->assertStatus(422);
        $this->postJson("/api/service-records/{$first}/approve", ['deducted_minutes' => 60], $nh)->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ApprovalRequest::where('entity_id', $first)->value('status'));

        $second = $log();
        $this->postJson("/api/service-records/{$second}/reject", ['reason' => ''], $nh)->assertStatus(422);
        $this->postJson("/api/service-records/{$second}/reject", ['reason' => 'Wrong job order'], $nh)->assertOk()
            ->assertJsonPath('status', 'rejected')->assertJsonPath('rejected_reason', 'Wrong job order');
        $this->assertSame('rejected', ApprovalRequest::where('entity_id', $second)->value('status'));
        $this->postJson("/api/service-records/{$second}/approve", ['deducted_minutes' => 60], $nh)->assertStatus(422);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'service_record', 'entity_id' => $second, 'action' => 'rejected', 'reason' => 'Wrong job order']);

        // A Service Record is decided on its own screen, never in the Approval Center.
        $third = $log();
        $request = ApprovalRequest::where('entity_id', $third)->sole();
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => 'approved'], $nh)->assertStatus(422);
        $this->assertTrue(ServiceRecordService::isApprover($nico));
        $this->assertSame([$nico->id], ServiceRecordService::approvers($this->company->id)->pluck('id')->all());
    }

    public function test_approvers_see_their_pending_items_without_core_administration_access(): void
    {
        [, $h] = $this->login(User::ROLE_FINANCE);
        [$signatory, $sh] = $this->login(User::ROLE_SUPPORT_ENGINEER, []);
        $bank = BankAccount::factory()->for($this->company)->create();
        $this->authority('Signatories', [$signatory], 'payment_voucher', 100);
        $pv = $this->pv($h, $bank, 500);

        $this->getJson('/api/approvals/pending', $sh)->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.summary', "Payment Voucher {$pv['voucher_number']}, SGD 500.00 to ".CompanyIndividual::whereKey($pv['supplier_id'])->value('name'));
    }
}
