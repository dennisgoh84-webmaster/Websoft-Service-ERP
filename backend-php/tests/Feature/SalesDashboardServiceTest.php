<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ContractService;
use App\Services\Numbering;
use App\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * NEW FEATURE (not a Python->PHP conversion -- backend/ has no
 * equivalent; built directly in backend-php per Dennis's request, see
 * docs/backlog.md / docs/planned-work.md): "Sales Dashboard". Pins the
 * worked examples behind each KPI -- financial-year boundary dates,
 * the AR bucket figures, and the Top 10 / Bottom 10 listings -- per
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist (applied here to new feature work, not a conversion).
 */
class SalesDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, CompanyIndividual $customer, float $amountSgd, Carbon $issuedAt): Invoice
    {
        // `issued_at` is intentionally NOT in Invoice::$fillable (its
        // DB column defaults to useCurrent(), set at real issuance
        // time) -- forceFill()+save() bypasses that guard so these
        // financial-year-boundary tests can backdate a row deliberately,
        // without changing App\Models\Invoice's mass-assignment
        // surface for the rest of the app.
        return tap(Invoice::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'invoice_number' => Numbering::next($company->id, 'invoice'),
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Test invoice',
            'amount_sgd' => $amountSgd,
            'gst_rate' => 0,
            'gst_amount_sgd' => 0,
            'total_amount_sgd' => $amountSgd,
        ]), fn (Invoice $i) => $i->forceFill(['issued_at' => $issuedAt])->save());
    }

    // ---- Financial-year boundary (calendar-year pragmatic default) ------

    public function test_financial_year_range_is_calendar_year(): void
    {
        $range = SalesDashboardService::financialYearRange(2026);

        $this->assertSame('2026-01-01', $range['start']->toDateString());
        $this->assertSame('2026-12-31', $range['end']->toDateString());
    }

    public function test_top_billing_customers_excludes_invoices_outside_the_financial_year(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $this->invoice($company, $customer, 1000, Carbon::parse('2025-12-31 23:59:59')); // last year -- excluded
        $this->invoice($company, $customer, 500, Carbon::parse('2026-01-01 00:00:00')); // in range
        $this->invoice($company, $customer, 500, Carbon::parse('2026-12-31 23:59:59')); // in range

        $rows = SalesDashboardService::topBillingCustomers($company->id, 2026);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(1000.0, $rows->first()['net_revenue_sgd'], 0.01);
    }

    public function test_top_billing_customers_ranks_by_net_of_gst_amount_not_total_with_gst(): void
    {
        $company = Company::factory()->create();
        $bigNetSmallTotal = CompanyIndividual::factory()->for($company)->create(['name' => 'Net Winner']);
        $smallNetBigTotal = CompanyIndividual::factory()->for($company)->create(['name' => 'Total Winner']);
        // Net-of-GST amount_sgd is what ranks -- not total_amount_sgd.
        // See invoice()'s docblock for why issued_at needs forceFill().
        tap(Invoice::create([
            'company_id' => $company->id, 'customer_id' => $bigNetSmallTotal->id,
            'invoice_number' => Numbering::next($company->id, 'invoice'), 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Test invoice',
            'amount_sgd' => 2000, 'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 2000,
        ]), fn (Invoice $i) => $i->forceFill(['issued_at' => Carbon::parse('2026-03-01')])->save());
        tap(Invoice::create([
            'company_id' => $company->id, 'customer_id' => $smallNetBigTotal->id,
            'invoice_number' => Numbering::next($company->id, 'invoice'), 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Test invoice',
            'amount_sgd' => 1000, 'gst_rate' => 9, 'gst_amount_sgd' => 500, 'total_amount_sgd' => 4000,
        ]), fn (Invoice $i) => $i->forceFill(['issued_at' => Carbon::parse('2026-03-01')])->save());

        $rows = SalesDashboardService::topBillingCustomers($company->id, 2026);

        $this->assertSame('Net Winner', $rows->first()['customer_name']);
    }

    public function test_bottom_non_active_customers_are_customers_with_zero_invoices_in_the_year(): void
    {
        $company = Company::factory()->create();
        $billed = CompanyIndividual::factory()->for($company)->create(['is_customer' => true, 'name' => 'Billed Co']);
        $unbilled = CompanyIndividual::factory()->for($company)->create(['is_customer' => true, 'name' => 'Unbilled Co']);
        $notACustomer = CompanyIndividual::factory()->for($company)->create(['is_customer' => false, 'is_supplier' => true, 'name' => 'Supplier Only']);
        $this->invoice($company, $billed, 100, Carbon::parse('2026-03-01'));

        $rows = SalesDashboardService::bottomNonActiveCustomers($company->id, 2026);

        $names = $rows->pluck('customer_name')->all();
        $this->assertContains('Unbilled Co', $names);
        $this->assertNotContains('Billed Co', $names);
        $this->assertNotContains('Supplier Only', $names); // is_customer=false
    }

    // ---- AR outstanding buckets (reuses AccountsReceivableService::agingBucketFor) ----

    public function test_ar_outstanding_2_and_3_month_buckets_match_the_31_60_and_61_90_day_aging_buckets(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $asAt = Carbon::parse('2026-06-01');
        // 45 days overdue -> 31_60 bucket ("2 months").
        Invoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'invoice_number' => Numbering::next($company->id, 'invoice'), 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Test invoice',
            'amount_sgd' => 1000, 'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 1000,
            'due_date' => $asAt->copy()->subDays(45), 'issued_at' => now(),
        ]);
        // 75 days overdue -> 61_90 bucket ("3 months").
        Invoice::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'invoice_number' => Numbering::next($company->id, 'invoice'), 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL, 'description' => 'Test invoice',
            'amount_sgd' => 2000, 'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 2000,
            'due_date' => $asAt->copy()->subDays(75), 'issued_at' => now(),
        ]);

        $twoMonths = SalesDashboardService::arOutstandingSum($company->id, '31_60', $asAt);
        $threeMonths = SalesDashboardService::arOutstandingSum($company->id, '61_90', $asAt);
        $total = SalesDashboardService::arOutstandingSum($company->id, 'total', $asAt);

        $this->assertEqualsWithDelta(1000.0, $twoMonths, 0.01);
        $this->assertEqualsWithDelta(2000.0, $threeMonths, 0.01);
        $this->assertEqualsWithDelta(3000.0, $total, 0.01); // total is NOT the sum of just these two buckets by coincidence -- it's every outstanding invoice
    }

    // ---- Contracts due for renewal (reuses ContractService::dueForRenewal) ----

    public function test_contracts_due_for_renewal_count_matches_contract_service(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: now()->subMonths(11)->toDateString(),
            actorUserId: User::factory()->for($company)->create()->id,
        );
        ContractService::activateContract($contract, $contract->sales_staff_id ?? User::factory()->for($company)->create()->id);
        $contract->end_date = now()->addDays(10)->toDateString();
        $contract->save();

        $this->assertSame(1, SalesDashboardService::contractsDueForRenewalCount($company->id));
    }

    // ---- Quotations KPIs: KNOWN GAP, never fabricated --------------------

    public function test_quotations_pending_kpis_report_not_available_not_fabricated(): void
    {
        $company = Company::factory()->create();

        $approval = SalesDashboardService::quotationsPendingApproval($company->id);
        $confirmation = SalesDashboardService::quotationsPendingConfirmation($company->id);

        $this->assertSame(0, $approval['count']);
        $this->assertTrue($approval['not_available']);
        $this->assertSame(0, $confirmation['count']);
        $this->assertTrue($confirmation['not_available']);
    }
}
