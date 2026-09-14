<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\QuotationService, mirroring
 * backend/app/services/quotations.py exactly (totals/GST, and the
 * confirmed 2026-09-10 accept -> auto-Contract splitting rule). See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class QuotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuotation(Company $company, CompanyIndividual $customer): Quotation
    {
        return Quotation::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_recompute_totals_sums_lines_net_of_gst_with_no_tax_code_configured(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'Consulting', 'quantity' => 10,
            'unit_price_sgd' => 100, 'line_total_sgd' => 1000,
        ]);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'Software licence', 'quantity' => 1,
            'unit_price_sgd' => 500, 'line_total_sgd' => 500,
        ]);
        $quotation->load('lines');

        QuotationService::recomputeTotals($quotation);

        $this->assertSame('1500.00', $quotation->amount_sgd);
        $this->assertSame('SR', $quotation->tax_code);
        // No tax_codes row for this company by default -- GST is 0.00,
        // never invented (see App\Services\Tax::applyGst's docblock).
        $this->assertSame('0.00', $quotation->gst_amount_sgd);
        $this->assertSame('1500.00', $quotation->total_amount_sgd);
    }

    public function test_recompute_totals_applies_gst_when_a_tax_code_is_configured(): void
    {
        $company = Company::factory()->create();
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard-rated', 'rate_percent' => 9, 'is_active' => true]);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'Consulting', 'quantity' => 10,
            'unit_price_sgd' => 100, 'line_total_sgd' => 1000,
        ]);
        $quotation->load('lines');

        QuotationService::recomputeTotals($quotation);

        $this->assertSame('9.00', $quotation->gst_rate);
        $this->assertSame('90.00', $quotation->gst_amount_sgd); // 1000 * 9%
        $this->assertSame('1090.00', $quotation->total_amount_sgd);
    }

    public function test_accept_with_only_hourly_lines_creates_one_service_support_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'On-site support', 'unit_of_measure' => 'Hours',
            'quantity' => 10, 'unit_price_sgd' => 300, 'line_total_sgd' => 3000,
        ]);
        $quotation->load('lines');

        $message = QuotationService::acceptQuotation($quotation, $actor->id);

        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->status);
        $this->assertNotNull($quotation->converted_contract_id);
        $this->assertNull($quotation->converted_annual_contract_id);
        $contract = Contract::find($quotation->converted_contract_id);
        $this->assertSame(Contract::KIND_SERVICE_SUPPORT, $contract->contract_kind);
        $this->assertSame(600, $contract->contracted_minutes); // 10 hrs
        $this->assertEqualsWithDelta(3000.0, (float) $contract->contract_value_sgd, 0.01);
        $this->assertSame('Quotation accepted. Service Support contract created (10 hrs).', $message);
    }

    public function test_accept_with_only_non_hourly_lines_creates_one_annual_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'Annual software warranty', 'unit_of_measure' => 'Year',
            'quantity' => 1, 'unit_price_sgd' => 1200, 'line_total_sgd' => 1200,
        ]);
        $quotation->load('lines');

        $message = QuotationService::acceptQuotation($quotation, $actor->id);

        $this->assertNull($quotation->converted_contract_id);
        $this->assertNotNull($quotation->converted_annual_contract_id);
        $contract = Contract::find($quotation->converted_annual_contract_id);
        $this->assertSame(Contract::KIND_ANNUAL, $contract->contract_kind);
        $this->assertSame(0, $contract->contracted_minutes);
        $this->assertEqualsWithDelta(1200.0, (float) $contract->contract_value_sgd, 0.01);
        $this->assertSame('Quotation accepted. Annual contract created (SGD 1200.00, 12-month term).', $message);
    }

    public function test_accept_with_mixed_lines_creates_two_separate_contracts_never_one_blend(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'On-site support', 'unit_of_measure' => 'hour',
            'quantity' => 10, 'unit_price_sgd' => 300, 'line_total_sgd' => 3000,
        ]);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'Annual software warranty', 'unit_of_measure' => 'Year',
            'quantity' => 1, 'unit_price_sgd' => 1200, 'line_total_sgd' => 1200,
        ]);
        $quotation->load('lines');

        $message = QuotationService::acceptQuotation($quotation, $actor->id);

        $this->assertNotNull($quotation->converted_contract_id);
        $this->assertNotNull($quotation->converted_annual_contract_id);
        $this->assertNotSame($quotation->converted_contract_id, $quotation->converted_annual_contract_id);
        $this->assertStringContainsString('Service Support contract created (10 hrs).', $message);
        $this->assertStringContainsString('Annual contract created (SGD 1200.00, 12-month term).', $message);
    }

    public function test_accept_below_srv_002_minimum_hours_leaves_hourly_half_unconverted_but_still_accepts(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);
        QuotationLine::create([
            'quotation_id' => $quotation->id, 'description' => 'On-site support', 'unit_of_measure' => 'Hours',
            'quantity' => 5, 'unit_price_sgd' => 300, 'line_total_sgd' => 1500,
        ]);
        $quotation->load('lines');

        $message = QuotationService::acceptQuotation($quotation, $actor->id);

        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->status);
        $this->assertNull($quotation->converted_contract_id);
        $this->assertStringContainsString('SRV-002', $message);
        $this->assertStringContainsString('Hourly lines not converted to a contract', $message);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'quotation', 'entity_id' => $quotation->id, 'action' => 'accepted',
        ]);
    }

    public function test_accept_with_no_lines_converts_neither(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $quotation = $this->makeQuotation($company, $customer);

        $message = QuotationService::acceptQuotation($quotation, $actor->id);

        $this->assertNull($quotation->converted_contract_id);
        $this->assertNull($quotation->converted_annual_contract_id);
        $this->assertSame('Quotation accepted.', $message);
    }
}
