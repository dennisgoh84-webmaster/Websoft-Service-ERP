<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Invoice;
use App\Models\ServiceRecord;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\BillingService, mirroring
 * backend/app/services/billing.py exactly (BILL-001/002/005,
 * SRV-008). See docs/php-conversion-plan.md's "after converting each
 * module" checklist.
 */
class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_annual_invoice_carries_the_full_contract_value_and_gst(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create(['payment_terms_days' => 30]);
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        $invoice = BillingService::issueContractAnnualInvoice($contract, $actor->id);

        $this->assertSame(Invoice::TYPE_CONTRACT_ANNUAL, $invoice->invoice_type);
        $this->assertEqualsWithDelta(3000.0, (float) $invoice->amount_sgd, 0.01);
        // No tax_codes row for this company by default -- GST is 0.00,
        // never invented (see Tax::applyGst's docblock).
        $this->assertEqualsWithDelta(0.0, (float) $invoice->gst_amount_sgd, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $invoice->total_amount_sgd, 0.01);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_date->toDateString());
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'invoice', 'entity_id' => $invoice->id, 'action' => 'issued',
        ]);
    }

    public function test_gst_is_applied_when_a_tax_code_is_configured(): void
    {
        $company = Company::factory()->create();
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard-rated', 'rate_percent' => 9, 'is_active' => true]);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        $invoice = BillingService::issueContractAnnualInvoice($contract, $actor->id);

        $this->assertSame('SR', $invoice->tax_code);
        $this->assertEqualsWithDelta(9.0, (float) $invoice->gst_rate, 0.01);
        $this->assertEqualsWithDelta(90.0, (float) $invoice->gst_amount_sgd, 0.01); // 1000 * 9%
        $this->assertEqualsWithDelta(1090.0, (float) $invoice->total_amount_sgd, 0.01);
    }

    public function test_no_due_date_when_customer_has_no_agreed_payment_terms(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create(['payment_terms_days' => null]);
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        $invoice = BillingService::issueContractAnnualInvoice($contract, $actor->id);

        $this->assertNull($invoice->due_date);
    }

    public function test_blended_rate_per_hour_divides_contract_value_by_contracted_hours(): void
    {
        $company = Company::factory()->create();
        $contract = Contract::factory()->for($company)->create([
            'contracted_minutes' => 600, 'contract_value_sgd' => 3000, // 10 hrs
        ]);

        $rate = BillingService::blendedRatePerHour($contract);

        $this->assertSame('300.00', $rate->toString());
    }

    public function test_excess_usage_invoice_is_billed_at_the_blended_rate(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'contracted_minutes' => 600, 'contract_value_sgd' => 3000,
        ]);
        $excess = ExcessUsageRecord::create([
            'company_id' => $company->id, 'contract_id' => $contract->id,
            'service_record_id' => ServiceRecord::factory()->for($company)->create()->id,
            'excess_minutes' => 40,
        ]);

        $invoice = BillingService::issueExcessUsageInvoice($excess, $contract, $actor->id);

        $this->assertSame(Invoice::TYPE_EXCESS_USAGE, $invoice->invoice_type);
        $this->assertEqualsWithDelta(200.0, (float) $invoice->amount_sgd, 0.01); // 300/hr * 2/3 hr
        $this->assertTrue($excess->fresh()->invoiced);
        $this->assertSame($excess->id, $invoice->excess_usage_record_id);
    }
}
