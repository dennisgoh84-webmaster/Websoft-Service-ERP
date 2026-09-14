<?php

namespace Tests\Feature;

use App\Exceptions\ARRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\AccountsReceivableService,
 * mirroring backend/app/services/accounts_receivable.py's AR-002/003
 * rules exactly. See docs/php-conversion-plan.md's "after converting
 * each module" checklist.
 */
class AccountsReceivableServiceTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, float $value = 1000): Invoice
    {
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: $value, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        return BillingService::issueContractAnnualInvoice($contract, $actor->id);
    }

    // ---- SRV/AR-002: write-off -------------------------------------------

    public function test_owner_can_always_write_off(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $invoice = $this->invoice($company, 100000);

        AccountsReceivableService::writeOffInvoice($invoice, $owner, 'Customer insolvent');

        $this->assertSame(Invoice::STATUS_WRITTEN_OFF, $invoice->fresh()->status);
    }

    public function test_non_owner_cannot_write_off_when_no_threshold_is_set(): void
    {
        $company = Company::factory()->create();
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $invoice = $this->invoice($company, 10);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::writeOffInvoice($invoice, $staff, 'Small balance');
    }

    public function test_non_owner_can_write_off_below_the_configured_threshold(): void
    {
        $company = Company::factory()->create(['write_off_approval_threshold_sgd' => 50]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $invoice = $this->invoice($company, 10);

        AccountsReceivableService::writeOffInvoice($invoice, $staff, 'Small balance');

        $this->assertSame(Invoice::STATUS_WRITTEN_OFF, $invoice->fresh()->status);
    }

    public function test_non_owner_cannot_write_off_above_the_configured_threshold(): void
    {
        $company = Company::factory()->create(['write_off_approval_threshold_sgd' => 50]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $invoice = $this->invoice($company, 1000);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::writeOffInvoice($invoice, $staff, 'Big balance');
    }

    public function test_a_reason_is_required(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $invoice = $this->invoice($company);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::writeOffInvoice($invoice, $owner, '  ');
    }

    public function test_cannot_write_off_an_already_written_off_invoice(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $invoice = $this->invoice($company);
        AccountsReceivableService::writeOffInvoice($invoice, $owner, 'First write-off');

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::writeOffInvoice($invoice->fresh(), $owner, 'Second attempt');
    }

    public function test_cannot_write_off_a_fully_paid_invoice(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $invoice = $this->invoice($company);
        $invoice->update(['status' => Invoice::STATUS_PAID, 'amount_paid_sgd' => $invoice->total_amount_sgd]);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::writeOffInvoice($invoice->fresh(), $owner, 'Nothing to write off');
    }

    // ---- Aging buckets -----------------------------------------------------

    public function test_aging_bucket_examples(): void
    {
        $asAt = Carbon::parse('2026-09-14');

        $this->assertSame('current', AccountsReceivableService::agingBucketFor(null, $asAt));
        $this->assertSame('current', AccountsReceivableService::agingBucketFor(Carbon::parse('2026-09-14'), $asAt));
        $this->assertSame('current', AccountsReceivableService::agingBucketFor(Carbon::parse('2026-09-20'), $asAt));
        $this->assertSame('1_30', AccountsReceivableService::agingBucketFor(Carbon::parse('2026-09-01'), $asAt));
        $this->assertSame('31_60', AccountsReceivableService::agingBucketFor(Carbon::parse('2026-07-30'), $asAt));
        $this->assertSame('61_90', AccountsReceivableService::agingBucketFor(Carbon::parse('2026-07-01'), $asAt));
        $this->assertSame('over_90', AccountsReceivableService::agingBucketFor(Carbon::parse('2026-01-01'), $asAt));
    }

    public function test_aging_rows_bucket_outstanding_invoices_by_customer(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->invoice($company);
        // Simulate an invoice that fell overdue 45 days ago -- due_date
        // is derived from payment terms at issue time, independent of
        // the aging bucket logic under test here.
        $invoice->update(['due_date' => Carbon::now()->subDays(45)->toDateString()]);

        [$asAt, $rows] = AccountsReceivableService::agingRows($company->id);

        $this->assertCount(1, $rows);
        $this->assertSame($invoice->customer_id, $rows[0]['customer_id']);
        $this->assertGreaterThan(0, $rows[0]['days_31_60']);
        $this->assertEqualsWithDelta($rows[0]['total'], $rows[0]['days_31_60'], 0.01);
    }

    public function test_written_off_invoices_are_excluded_from_aging(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $invoice = $this->invoice($company);
        AccountsReceivableService::writeOffInvoice($invoice, $owner, 'Bad debt');

        [, $rows] = AccountsReceivableService::agingRows($company->id);

        $this->assertCount(0, $rows);
    }

    // ---- AR-001: payment allocation -----------------------------------------

    public function test_allocating_a_payment_marks_the_invoice_partially_paid_then_paid(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->invoice($company, 1000); // total incl. GST 0 by default (no tax code)
        $payment = Payment::factory()->for($company)->create(['customer_id' => $invoice->customer_id, 'amount_sgd' => 1000]);

        AccountsReceivableService::allocatePayment($payment, $invoice, Money::of(400));
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(400.0, (float) $invoice->fresh()->amount_paid_sgd, 0.01);

        AccountsReceivableService::allocatePayment($payment, $invoice->fresh(), Money::of(600));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_cannot_allocate_more_than_the_payment_has_unallocated(): void
    {
        $company = Company::factory()->create();
        $invoiceA = $this->invoice($company, 1000);
        $invoiceB = $this->invoice($company, 1000);
        $invoiceB->update(['customer_id' => $invoiceA->customer_id]);
        $payment = Payment::factory()->for($company)->create(['customer_id' => $invoiceA->customer_id, 'amount_sgd' => 500]);
        AccountsReceivableService::allocatePayment($payment, $invoiceA, Money::of(500));

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::allocatePayment($payment, $invoiceB->fresh(), Money::of(1));
    }

    public function test_cannot_allocate_more_than_the_invoice_has_outstanding(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->invoice($company, 500);
        $payment = Payment::factory()->for($company)->create(['customer_id' => $invoice->customer_id, 'amount_sgd' => 5000]);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::allocatePayment($payment, $invoice, Money::of(5000));
    }

    public function test_cannot_allocate_against_an_invoice_for_a_different_customer(): void
    {
        $company = Company::factory()->create();
        $invoice = $this->invoice($company, 500);
        $otherCustomer = CompanyIndividual::factory()->for($company)->create();
        $payment = Payment::factory()->for($company)->create(['customer_id' => $otherCustomer->id, 'amount_sgd' => 500]);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::allocatePayment($payment, $invoice, Money::of(100));
    }

    public function test_cannot_allocate_against_a_written_off_invoice(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $invoice = $this->invoice($company, 500);
        AccountsReceivableService::writeOffInvoice($invoice, $owner, 'Bad debt');
        $payment = Payment::factory()->for($company)->create(['customer_id' => $invoice->customer_id, 'amount_sgd' => 500]);

        $this->expectException(ARRuleViolation::class);
        AccountsReceivableService::allocatePayment($payment, $invoice->fresh(), Money::of(100));
    }
}
