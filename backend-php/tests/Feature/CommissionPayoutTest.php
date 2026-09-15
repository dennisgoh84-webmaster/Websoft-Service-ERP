<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\CommissionPayout;
use App\Models\CommissionSettings;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commission Payouts -- backend/app/routers/commissions.py converted
 * to PHP/Laravel (docs/open-business-decisions.md 6.3 approval, 6.4
 * clawback, 6.5 payout).
 *
 * Covers the DRAFT -> PENDING_APPROVAL -> APPROVED -> PAID lifecycle
 * and every refusal along it, the batch actions, the authority split
 * (VIEW to read, EDIT to submit, FULL to approve/pay), and the
 * clawback an AR write-off raises.
 */
class CommissionPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * A company with a 10% rate and one receipt settling half of a
     * 1090 (net 1000, cost 400, so 60% GP) invoice -- 30.00 of
     * commission for one salesperson.
     *
     * @return array{0: Company, 1: string, 2: User}
     */
    private function companyWithOneMonthOfCommission(string $rate = '10.00'): array
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $salesStaff = User::factory()->for($company)->create(['full_name' => 'Chan Mei Ling']);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'sales_staff_id' => $salesStaff->id,
        ]);
        $invoice = Invoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'contract_id' => $contract->id, 'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Annual support',
            'amount_sgd' => '1000.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '90.00', 'total_amount_sgd' => '1090.00', 'cost_sgd' => '400.00',
        ]);
        $invoice->forceFill(['issued_at' => now()])->save();
        $payment = Payment::factory()->for($company)->create([
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'amount_sgd' => '545.00',
        ]);
        PaymentAllocation::create([
            'company_id' => $company->id, 'payment_id' => $payment->id,
            'invoice_id' => $invoice->id, 'amount_sgd' => '545.00',
        ]);
        CommissionSettings::create(['company_id' => $company->id, 'rate_percent' => $rate]);

        return [$company, $token, $salesStaff];
    }

    private function month(): string
    {
        return now()->format('Y-m');
    }

    // ── Generation ──────────────────────────────────────────────────

    public function test_generating_creates_one_draft_payout_per_salesperson(): void
    {
        [$company, $token, $salesStaff] = $this->companyWithOneMonthOfCommission();

        $response = $this->postJson(
            '/api/commissions/payouts/generate',
            ['period_month' => $this->month()],
            $this->headers($token),
        );

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame('draft', $response->json('0.status'));
        $this->assertSame('earning', $response->json('0.payout_type'));
        $this->assertSame($salesStaff->id, $response->json('0.sales_staff_id'));
        // Same figure the Commission report gives: half the total
        // settled -> half the net revenue (500) -> 60% GP (300) ->
        // 10% commission.
        $this->assertEqualsWithDelta(30, $response->json('0.amount_sgd'), 0.001);
        $this->assertEqualsWithDelta(10, $response->json('0.rate_percent'), 0.001);
        $this->assertStringStartsWith('CP-', $response->json('0.payout_number'));

        $entry = AuditLogEntry::where('entity_type', 'commission_payout')->where('action', 'generated')->first();
        $this->assertNotNull($entry);
    }

    public function test_generated_payouts_match_the_commission_report_for_the_same_month(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $month = $this->month();

        $report = $this->getJson(
            '/api/reports/accounting/commission?period_start='.now()->startOfMonth()->toDateString()
                .'&period_end='.now()->endOfMonth()->toDateString(),
            $this->headers($token),
        )->assertOk()->json();
        $payouts = $this->postJson(
            '/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token),
        )->assertOk()->json();

        $this->assertEqualsWithDelta(
            $report['total_commission_sgd'],
            array_sum(array_column($payouts, 'amount_sgd')),
            0.001,
        );
    }

    public function test_generating_twice_for_the_same_month_is_refused(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $month = $this->month();
        $this->postJson('/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token))
            ->assertOk();

        // Regenerating would silently double what is owed.
        $this->postJson('/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_a_cancelled_batch_can_be_regenerated(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $month = $this->month();
        $first = $this->postJson('/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token))
            ->assertOk()->json();
        $this->postJson("/api/commissions/payouts/{$first[0]['id']}/cancel", [], $this->headers($token))->assertOk();

        $this->postJson('/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token))
            ->assertOk()->assertJsonCount(2);
    }

    public function test_a_month_with_no_commission_generates_nothing(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/commissions/payouts/generate', ['period_month' => $this->month()], $this->headers($token))
            ->assertOk()->assertJsonCount(0);
    }

    public function test_period_month_must_be_yyyy_mm(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/commissions/payouts/generate', ['period_month' => 'Sept 2026'], $this->headers($token))
            ->assertStatus(422);
    }

    // ── Lifecycle ───────────────────────────────────────────────────

    private function generateOne(string $token): array
    {
        return $this->postJson(
            '/api/commissions/payouts/generate', ['period_month' => $this->month()], $this->headers($token),
        )->assertOk()->json('0');
    }

    public function test_full_lifecycle_draft_to_paid(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $payout = $this->generateOne($token);
        $id = $payout['id'];

        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'pending_approval']);
        $this->postJson("/api/commissions/payouts/{$id}/approve", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'approved']);

        $paid = $this->postJson(
            "/api/commissions/payouts/{$id}/pay",
            ['paid_date' => now()->toDateString(), 'paid_reference' => 'GIRO-8891'],
            $this->headers($token),
        )->assertOk();

        $paid->assertJson(['status' => 'paid', 'paid_reference' => 'GIRO-8891']);
        $this->assertSame(now()->toDateString(), $paid->json('paid_date'));
        $this->assertNotNull($paid->json('approved_at'));
        $this->assertNotNull($paid->json('submitted_at'));

        foreach (['submitted', 'approved', 'paid'] as $action) {
            $this->assertTrue(
                AuditLogEntry::where('entity_type', 'commission_payout')->where('action', $action)->exists(),
                "no audit entry for {$action}",
            );
        }
    }

    public function test_each_transition_refuses_a_payout_in_the_wrong_state(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $id = $this->generateOne($token)['id'];

        // A DRAFT cannot be approved or paid straight away.
        $this->postJson("/api/commissions/payouts/{$id}/approve", [], $this->headers($token))->assertStatus(422);
        $this->postJson(
            "/api/commissions/payouts/{$id}/pay", ['paid_date' => now()->toDateString()], $this->headers($token),
        )->assertStatus(422);

        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($token))->assertOk();
        // Nor submitted twice, nor paid before approval.
        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($token))->assertStatus(422);
        $this->postJson(
            "/api/commissions/payouts/{$id}/pay", ['paid_date' => now()->toDateString()], $this->headers($token),
        )->assertStatus(422);
    }

    public function test_rejecting_returns_it_to_draft_and_records_the_reason(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $id = $this->generateOne($token)['id'];
        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($token))->assertOk();

        $response = $this->postJson(
            "/api/commissions/payouts/{$id}/reject",
            ['reason' => 'Wrong month'],
            $this->headers($token),
        );

        $response->assertOk()->assertJson(['status' => 'draft']);
        $this->assertStringContainsString('Rejected: Wrong month', $response->json('notes'));
    }

    public function test_a_paid_payout_can_never_be_cancelled(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        $id = $this->generateOne($token)['id'];
        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($token))->assertOk();
        $this->postJson("/api/commissions/payouts/{$id}/approve", [], $this->headers($token))->assertOk();
        $this->postJson(
            "/api/commissions/payouts/{$id}/pay", ['paid_date' => now()->toDateString()], $this->headers($token),
        )->assertOk();

        $this->postJson("/api/commissions/payouts/{$id}/cancel", [], $this->headers($token))->assertStatus(422);
        // And the record survives -- money paid is never deleted.
        $this->assertSame('paid', CommissionPayout::find($id)->status);
    }

    // ── Batch actions ───────────────────────────────────────────────

    public function test_submit_all_and_approve_all_move_only_the_right_rows(): void
    {
        [$company, $token] = $this->companyWithOneMonthOfCommission();
        $month = $this->month();
        $this->postJson('/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token))
            ->assertOk();
        // A payout from another month must not be swept along.
        $other = CommissionPayout::create([
            'company_id' => $company->id, 'payout_number' => 'CP-2000-0001',
            'sales_staff_id' => User::factory()->for($company)->create()->id,
            'period_month' => '2000-01', 'period_start' => '2000-01-01', 'period_end' => '2000-01-31',
            'amount_sgd' => '5.00', 'rate_percent' => '10.00',
        ]);

        $this->postJson('/api/commissions/payouts/submit-all', ['period_month' => $month], $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.status', 'pending_approval');
        $this->postJson('/api/commissions/payouts/approve-all', ['period_month' => $month], $this->headers($token))
            ->assertOk()->assertJsonPath('0.status', 'approved');

        $this->assertSame('draft', $other->fresh()->status);
    }

    // ── Listing ─────────────────────────────────────────────────────

    public function test_list_filters_by_month_status_and_salesperson(): void
    {
        [, $token, $salesStaff] = $this->companyWithOneMonthOfCommission();
        $month = $this->month();
        $this->postJson('/api/commissions/payouts/generate', ['period_month' => $month], $this->headers($token))
            ->assertOk();

        $this->getJson("/api/commissions/payouts?period_month={$month}", $this->headers($token))
            ->assertOk()->assertJsonCount(1);
        $this->getJson('/api/commissions/payouts?period_month=1999-01', $this->headers($token))
            ->assertOk()->assertJsonCount(0);
        $this->getJson('/api/commissions/payouts?status=draft', $this->headers($token))
            ->assertOk()->assertJsonCount(1);
        $this->getJson('/api/commissions/payouts?status=paid', $this->headers($token))
            ->assertOk()->assertJsonCount(0);
        $this->getJson("/api/commissions/payouts?sales_staff_id={$salesStaff->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(1);
        $this->getJson('/api/commissions/payouts?status=nonsense', $this->headers($token))
            ->assertStatus(422);
    }

    public function test_another_companys_payout_is_not_found(): void
    {
        [, $token] = $this->companyWithOneMonthOfCommission();
        [$otherCompany, $otherToken] = $this->companyWithOneMonthOfCommission();
        $theirs = $this->generateOne($otherToken)['id'];

        $this->getJson("/api/commissions/payouts/{$theirs}", $this->headers($token))->assertStatus(404);
        $this->getJson('/api/commissions/payouts', $this->headers($token))->assertOk()->assertJsonCount(0);
        $this->assertSame($otherCompany->id, CommissionPayout::find($theirs)->company_id);
    }

    // ── Clawback (6.4) ──────────────────────────────────────────────

    public function test_writing_off_an_invoice_raises_a_negative_clawback(): void
    {
        [$company, $token] = $this->companyWithOneMonthOfCommission();
        $invoice = Invoice::where('company_id', $company->id)->firstOrFail();

        $this->postJson(
            "/api/accounts-receivable/invoices/{$invoice->id}/write-off",
            ['reason' => 'Customer liquidated'],
            $this->headers($token),
        )->assertOk();

        $clawback = CommissionPayout::where('company_id', $company->id)
            ->where('payout_type', CommissionPayout::TYPE_CLAWBACK)->firstOrFail();
        // The mirror of the 30.00 earned, and auto-approved: it
        // reduces what is owed, so it does not wait on an approval.
        $this->assertEqualsWithDelta(-30, (float) $clawback->amount_sgd, 0.001);
        $this->assertSame(CommissionPayout::STATUS_APPROVED, $clawback->status);
        $this->assertSame($invoice->id, $clawback->clawback_invoice_id);
        $this->assertStringContainsString('Customer liquidated', $clawback->clawback_reason);
        $this->assertTrue(
            AuditLogEntry::where('entity_type', 'commission_payout')
                ->where('action', 'clawback_created')->exists(),
        );
    }

    public function test_a_write_off_raises_no_clawback_while_no_rate_is_set(): void
    {
        [$company, $token] = $this->companyWithOneMonthOfCommission('0.00');
        $invoice = Invoice::where('company_id', $company->id)->firstOrFail();

        $this->postJson(
            "/api/accounts-receivable/invoices/{$invoice->id}/write-off",
            ['reason' => 'Customer liquidated'],
            $this->headers($token),
        )->assertOk();

        $this->assertSame(0, CommissionPayout::where('company_id', $company->id)->count());
    }

    // ── Authority ───────────────────────────────────────────────────

    private function staffToken(Company $company, string $level): string
    {
        ModuleCatalog::firstOrCreate(['key' => 'accounting_reports'], ['name' => 'Accounting Reports', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => 'accounting_reports'],
            ['enabled' => true],
        );
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => 'accounting_reports', 'access_level' => $level,
        ]);
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);

        return $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    public function test_view_reads_edit_submits_and_only_full_approves_or_pays(): void
    {
        [$company, $ownerToken] = $this->companyWithOneMonthOfCommission();
        $id = $this->generateOne($ownerToken)['id'];

        $viewToken = $this->staffToken($company, GroupModuleAuthority::VIEW);
        $this->getJson('/api/commissions/payouts', $this->headers($viewToken))->assertOk();
        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($viewToken))->assertStatus(403);

        $editToken = $this->staffToken($company, GroupModuleAuthority::EDIT);
        $this->postJson("/api/commissions/payouts/{$id}/submit", [], $this->headers($editToken))->assertOk();
        // Preparing a batch is not the same as approving or paying it.
        $this->postJson("/api/commissions/payouts/{$id}/approve", [], $this->headers($editToken))->assertStatus(403);
        $this->postJson(
            '/api/commissions/payouts/generate', ['period_month' => '1999-01'], $this->headers($editToken),
        )->assertStatus(403);

        $fullToken = $this->staffToken($company, GroupModuleAuthority::FULL);
        $this->postJson("/api/commissions/payouts/{$id}/approve", [], $this->headers($fullToken))->assertOk();
        $this->postJson(
            "/api/commissions/payouts/{$id}/pay", ['paid_date' => now()->toDateString()], $this->headers($fullToken),
        )->assertOk();
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->getJson('/api/commissions/payouts', $this->headers($token))->assertStatus(403);
    }
}
