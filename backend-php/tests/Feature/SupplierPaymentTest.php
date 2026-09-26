<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\PasswordPolicy;
use App\Services\PayablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\SupplierPaymentController
 * (Payment Vouchers), mirroring the payment-voucher half of
 * backend/app/routers/payables.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class SupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): array
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return [$owner, $login->json('access_token')];
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function matchedBill(Company $company, User $actor, CompanyIndividual $supplier, float $amount = 545): SupplierInvoice
    {
        $po = PurchaseOrder::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'status' => PurchaseOrder::STATUS_APPROVED, 'total_amount_sgd' => $amount,
        ]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'amount_sgd' => $amount, 'gst_amount_sgd' => 0, 'total_amount_sgd' => $amount,
        ]);
        PayablesService::matchBillToPo($bill, $actor->id);

        return $bill->fresh();
    }

    public function test_owner_can_raise_and_allocate_a_payment_voucher(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $bill = $this->matchedBill($company, $owner, $supplier, 545);

        $create = $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 545, 'bank_account_id' => $bank->id,
            'allocations' => [['supplier_invoice_id' => $bill->id, 'amount_sgd' => 545]],
        ], $this->headers($token));

        $create->assertOk();
        $this->assertStringStartsWith('PV-', $create->json('voucher_number'));
        $this->assertEqualsWithDelta(545.0, $create->json('allocated_sgd'), 0.01);
        $this->assertEqualsWithDelta(0.0, $create->json('unallocated_sgd'), 0.01);
        $this->assertSame('posted', $create->json('gl_status'));
        $this->assertSame('paid', $bill->fresh()->status);
        // Each allocation names its bill (the screen showed "undefined" until 2026-09-26).
        $this->assertSame($bill->bill_number, $create->json('allocations.0.bill_number'));
    }

    public function test_a_single_payment_can_allocate_across_two_bills_in_one_request(): void
    {
        // Regression test: allocateSupplierPayment() must refresh the
        // payment's cached `allocations` relation after each write, or
        // the second line's unallocated-balance check sees a stale
        // (empty) collection and wrongly allows over-allocating.
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $billA = $this->matchedBill($company, $owner, $supplier, 300);
        $billB = $this->matchedBill($company, $owner, $supplier, 300);

        $create = $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 600, 'bank_account_id' => $bank->id,
            'allocations' => [
                ['supplier_invoice_id' => $billA->id, 'amount_sgd' => 300],
                ['supplier_invoice_id' => $billB->id, 'amount_sgd' => 300],
            ],
        ], $this->headers($token));

        $create->assertOk();
        $this->assertEqualsWithDelta(600.0, $create->json('allocated_sgd'), 0.01);
        $this->assertEqualsWithDelta(0.0, $create->json('unallocated_sgd'), 0.01);
        $this->assertSame('paid', $billA->fresh()->status);
        $this->assertSame('paid', $billB->fresh()->status);
    }

    public function test_payment_without_a_bank_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);

        $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(), 'amount_sgd' => 100,
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_allocating_more_than_the_bill_outstanding_is_rejected(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $bill = $this->matchedBill($company, $owner, $supplier, 100);

        $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 500, 'bank_account_id' => $bank->id,
            'allocations' => [['supplier_invoice_id' => $bill->id, 'amount_sgd' => 500]],
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_owner_can_bank_and_unbank_a_payment_voucher(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $create = $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 300, 'bank_account_id' => $bank->id,
        ], $this->headers($token));

        $bankStep = $this->postJson("/api/accounts-payable/payments/{$create->json('id')}/bank", [], $this->headers($token));
        $bankStep->assertOk()->assertJson(['status' => 'banked']);

        $unbank = $this->postJson("/api/accounts-payable/payments/{$create->json('id')}/unbank", ['reason' => 'Wrong account'], $this->headers($token));
        $unbank->assertOk()->assertJson(['status' => 'unbanked']);
    }

    public function test_owner_can_ungl_a_payment_voucher(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $create = $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 300, 'bank_account_id' => $bank->id,
        ], $this->headers($token));

        $ungl = $this->postJson("/api/accounts-payable/payments/{$create->json('id')}/ungl", ['reason' => 'Raised in error'], $this->headers($token));

        $ungl->assertOk()->assertJson(['status' => 'reversed']);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounts-payable/payments', $this->headers($login->json('access_token')))->assertStatus(403);
    }
}
