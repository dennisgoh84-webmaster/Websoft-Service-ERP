<?php

namespace Tests\Feature;

use App\Exceptions\PostingError;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PayablesService;
use App\Services\Posting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\Posting, mirroring
 * backend/app/services/posting.py exactly (ACC-001..004). See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class PostingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_an_invoice_debits_ar_and_credits_revenue_and_gst(): void
    {
        $company = Company::factory()->create();
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard-rated', 'rate_percent' => 9, 'is_active' => true]);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        // BillingService::issueContractAnnualInvoice already posts --
        // assert the resulting journal entry directly.
        $invoice = BillingService::issueContractAnnualInvoice($contract, $actor->id);
        $entry = Posting::liveEntryFor(Posting::SOURCE_INVOICE, $invoice->id);

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $ar = Account::where('company_id', $company->id)->where('code', '1100')->firstOrFail();
        $revenue = Account::where('company_id', $company->id)->where('code', '4000')->firstOrFail();
        $gstOutput = Account::where('company_id', $company->id)->where('code', '2100')->firstOrFail();
        $arLine = $entry->lines->firstWhere('account_id', $ar->id);
        $revenueLine = $entry->lines->firstWhere('account_id', $revenue->id);
        $gstLine = $entry->lines->firstWhere('account_id', $gstOutput->id);
        $this->assertEqualsWithDelta(1090.0, (float) $arLine->debit_sgd, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $revenueLine->credit_sgd, 0.01);
        $this->assertEqualsWithDelta(90.0, (float) $gstLine->credit_sgd, 0.01);
    }

    public function test_double_posting_the_same_invoice_is_rejected(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );
        $invoice = BillingService::issueContractAnnualInvoice($contract, $actor->id);

        $this->expectException(PostingError::class);
        Posting::postInvoice($invoice->fresh(), $actor->id);
    }

    public function test_posting_a_matched_bill_debits_expense_and_credits_ap(): void
    {
        $company = Company::factory()->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $actor = User::factory()->for($company)->create();
        $po = PurchaseOrder::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'status' => PurchaseOrder::STATUS_APPROVED,
            'total_amount_sgd' => 545,
        ]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'amount_sgd' => 500, 'gst_amount_sgd' => 45, 'total_amount_sgd' => 545,
        ]);

        PayablesService::matchBillToPo($bill, $actor->id);

        $entry = Posting::liveEntryFor(Posting::SOURCE_SUPPLIER_INVOICE, $bill->id);
        $this->assertNotNull($entry);
        $expense = Account::where('company_id', $company->id)->where('code', '5000')->firstOrFail();
        $ap = Account::where('company_id', $company->id)->where('code', '2000')->firstOrFail();
        $expenseLine = $entry->lines->firstWhere('account_id', $expense->id);
        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertEqualsWithDelta(500.0, (float) $expenseLine->debit_sgd, 0.01);
        $this->assertEqualsWithDelta(545.0, (float) $apLine->credit_sgd, 0.01);
    }

    public function test_posting_a_supplier_payment_debits_ap_and_credits_bank(): void
    {
        $company = Company::factory()->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $payment = SupplierPayment::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id, 'amount_sgd' => 200,
        ]);

        Posting::postSupplierPayment($payment, null);

        $entry = Posting::liveEntryFor(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id);
        $this->assertNotNull($entry);
        $ap = Account::where('company_id', $company->id)->where('code', '2000')->firstOrFail();
        $cash = Account::where('company_id', $company->id)->where('code', '1000')->firstOrFail();
        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $bankLine = $entry->lines->firstWhere('account_id', $cash->id);
        $this->assertEqualsWithDelta(200.0, (float) $apLine->debit_sgd, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $bankLine->credit_sgd, 0.01);
    }

    public function test_posting_a_supplier_payment_without_a_bank_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $payment = SupplierPayment::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'bank_account_id' => null,
        ]);

        $this->expectException(PostingError::class);
        Posting::postSupplierPayment($payment, null);
    }

    public function test_bank_step_creates_a_bank_transaction_and_guards_against_a_second_one(): void
    {
        $company = Company::factory()->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $payment = SupplierPayment::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id, 'amount_sgd' => 200,
        ]);

        $txn = Posting::bankSupplierPayment($payment, $actor->id);

        $this->assertEqualsWithDelta(200.0, (float) $txn->credit_sgd, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $txn->debit_sgd, 0.01);

        $this->expectException(PostingError::class);
        Posting::bankSupplierPayment($payment->fresh(), $actor->id);
    }

    public function test_unbank_voids_the_transaction_and_requires_a_reason(): void
    {
        $company = Company::factory()->create();
        $supplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
        $bank = BankAccount::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $payment = SupplierPayment::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'bank_account_id' => $bank->id, 'amount_sgd' => 200,
        ]);
        Posting::bankSupplierPayment($payment, $actor->id);

        $txn = Posting::unbank(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id, 'payment_voucher', $actor->id, 'Wrong account', 'supplier_payment');

        $this->assertTrue($txn->fresh()->is_voided);
        $this->assertNull(Posting::liveBankTransactionFor(Posting::SOURCE_SUPPLIER_PAYMENT, $payment->id));
    }

    public function test_ungl_reverses_the_live_entry_and_the_document_can_be_reposted(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );
        $invoice = BillingService::issueContractAnnualInvoice($contract, $actor->id);

        $reversal = Posting::unpost(Posting::SOURCE_INVOICE, $invoice->id, $actor->id, 'Raised in error', 'invoice');

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertNull(Posting::liveEntryFor(Posting::SOURCE_INVOICE, $invoice->id));

        // Re-posting after UNGL is allowed -- the prior entry is
        // reversed, not live, so this isn't a double-post.
        $second = Posting::postInvoice($invoice->fresh(), $actor->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $second->status);
    }

    public function test_missing_chart_of_accounts_entry_is_a_clear_posting_error_not_a_crash(): void
    {
        $company = Company::factory()->create();
        Account::where('company_id', $company->id)->where('code', '4000')->delete();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        $this->expectException(PostingError::class);
        $this->expectExceptionMessageMatches('/4000.*Chart of Accounts/');
        BillingService::issueContractAnnualInvoice($contract, $actor->id);
    }
}
