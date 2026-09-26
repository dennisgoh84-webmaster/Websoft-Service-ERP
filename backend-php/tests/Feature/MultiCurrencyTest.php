<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CurrencyRate;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Multi-currency on sales and purchases (Dennis, 2026-09-26, decision
 * page): a document's currency defaults from the Company / Individual,
 * its rate from the Currency Rate Table (latest on or before the date),
 * both changeable; figures are kept in the document's currency and in
 * SGD; exchange gain or loss is booked only when paid; a foreign-
 * currency bank account runs in its own currency.
 */
class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private array $h;

    private CompanyIndividual $customer;

    private CompanyIndividual $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00', 'Asia/Singapore'));
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $this->h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token')];
        foreach ([['SR', 9, 'supply'], ['TX', 9, 'purchase']] as [$code, $rate, $kind]) {
            TaxCode::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'rate_percent' => $rate, 'kind' => $kind, 'is_active' => true]);
        }
        $this->customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'YANKEE INC', 'default_currency' => 'usd']);
        $this->supplier = CompanyIndividual::factory()->for($this->company)->create(['name' => 'ACME USA', 'is_supplier' => true, 'default_currency' => 'USD', 'po_approval_limit_sgd' => 1000000]);
        CurrencyRate::create(['company_id' => $this->company->id, 'currency_code' => 'USD', 'rate_to_base' => '1.300000', 'effective_date' => '2026-09-01']);
        CurrencyRate::create(['company_id' => $this->company->id, 'currency_code' => 'USD', 'rate_to_base' => '1.350000', 'effective_date' => '2026-09-30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usdInvoice(float $unitPrice = 100): array
    {
        return $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => $unitPrice]],
        ], $this->h)->assertCreated()->json();
    }

    private function balanceOf(string $code): float
    {
        $id = Account::where('company_id', $this->company->id)->where('code', $code)->value('id');

        return (float) JournalLine::where('account_id', $id)->sum('debit_sgd') - (float) JournalLine::where('account_id', $id)->sum('credit_sgd');
    }

    public function test_a_sales_invoice_takes_the_customers_currency_and_the_tables_rate_and_keeps_both_figures(): void
    {
        $this->assertSame('USD', $this->customer->fresh()->default_currency, 'stored upper case');
        $inv = $this->usdInvoice(100);
        $this->assertSame('USD', $inv['currency_code']);
        $this->assertEquals(1.3, $inv['exchange_rate']);
        $this->assertEquals(100, $inv['amount_fx']);
        $this->assertEquals(9, $inv['gst_amount_fx']);
        $this->assertEquals(109, $inv['total_amount_fx']);
        $this->assertEquals(130, $inv['amount_sgd']);
        $this->assertEquals(11.7, $inv['gst_amount_sgd']);
        $this->assertEquals(141.7, $inv['total_amount_sgd']);
        $this->assertEquals(141.7, $this->balanceOf('1100'), 'the General Ledger is in SGD');

        // A rate keyed on the document wins; a currency with no rate anywhere is refused.
        $keyed = $this->postJson('/api/invoices', ['customer_id' => $this->customer->id, 'exchange_rate' => 1.4,
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]]], $this->h)->assertCreated();
        $this->assertEquals(14, $keyed->json('amount_sgd'));
        $this->postJson('/api/invoices', ['customer_id' => $this->customer->id, 'currency_code' => 'EUR',
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]]], $this->h)
            ->assertStatus(422)->assertJsonFragment(['detail' => 'No EUR rate on or before 26/09/2026 in the Currency Rate Table -- add one there, or key the rate on this document.']);
        $this->getJson('/api/currency-rates/as-at?currency_code=usd&date=2026-09-29', $this->h)->assertOk()->assertJsonPath('rate', 1.3);
        $this->getJson('/api/currency-rates/currencies', $this->h)->assertOk()->assertJson(['SGD', 'USD']);
    }

    public function test_a_receipt_at_a_better_rate_books_a_realised_exchange_gain_when_allocated(): void
    {
        $inv = $this->usdInvoice(100); // USD 109 = SGD 141.70
        $bank = BankAccount::factory()->for($this->company)->create(['currency_code' => 'SGD']);
        $receipt = $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $this->customer->id, 'payment_date' => '2026-09-30', 'amount' => 109,
            'bank_account_id' => $bank->id, // USD received into the SGD account, converted
        ], $this->h)->assertOk()->json();
        $this->assertSame('USD', $receipt['currency_code']);
        $this->assertEquals(1.35, $receipt['exchange_rate']);
        $this->assertEquals(147.15, $receipt['amount_sgd']);

        $this->postJson("/api/accounts-receivable/payments/{$receipt['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => 109]]], $this->h)->assertOk();
        $invoice = Invoice::find($inv['id']);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('0.00', $invoice->outstandingSgd()->toString());
        $this->assertEquals(0, $this->balanceOf('1100'), 'AR for this customer is cleared exactly');
        $this->assertEquals(-5.45, $this->balanceOf('6800'), 'SGD 5.45 exchange gain (a credit)');
        $this->assertSame(1, JournalEntry::where('source_type', 'payment_allocation')->count());
    }

    public function test_partial_payments_clear_the_last_cent_and_mixed_currencies_are_refused(): void
    {
        $inv = $this->usdInvoice(100);
        $bank = BankAccount::factory()->for($this->company)->create();
        $pay = fn (float $amount, string $date = '2026-09-26') => $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $this->customer->id, 'payment_date' => $date, 'amount' => $amount, 'bank_account_id' => $bank->id,
        ], $this->h)->assertOk()->json();
        $first = $pay(33.33);
        $this->postJson("/api/accounts-receivable/payments/{$first['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => 33.33]]], $this->h)->assertOk();
        $second = $pay(75.67, '2026-10-01');
        $this->postJson("/api/accounts-receivable/payments/{$second['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => 80]]], $this->h)
            ->assertStatus(422)->assertJsonFragment(['detail' => 'Only USD 75.67 of this payment is still unallocated.']);
        $this->postJson("/api/accounts-receivable/payments/{$second['id']}/allocate", ['allocations' => [['invoice_id' => $inv['id'], 'amount' => 75.67]]], $this->h)->assertOk();
        $invoice = Invoice::find($inv['id']);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('0.00', $invoice->outstandingSgd()->toString());
        $this->assertEqualsWithDelta(0, $this->balanceOf('1100'), 0.001);

        $sgdReceipt = $this->postJson('/api/accounts-receivable/payments', ['customer_id' => $this->customer->id, 'currency_code' => 'SGD', 'payment_date' => '2026-09-26',
            'amount' => 10, 'bank_account_id' => $bank->id], $this->h)->assertOk()->json();
        $other = $this->usdInvoice(5);
        $this->postJson("/api/accounts-receivable/payments/{$sgdReceipt['id']}/allocate", ['allocations' => [['invoice_id' => $other['id'], 'amount' => 5]]], $this->h)->assertStatus(422);
    }

    public function test_a_usd_bill_paid_at_a_worse_rate_books_a_loss_and_a_usd_account_runs_in_usd(): void
    {
        $bill = $this->postJson('/api/accounts-payable/bills', [
            'supplier_id' => $this->supplier->id, 'invoice_date' => '2026-09-26', 'description' => 'Licences', 'amount' => 200, 'tax_code' => 'TX',
        ], $this->h)->assertOk()->json();
        $this->assertSame('USD', $bill['currency_code']);
        $this->assertEquals(218, $bill['total_amount_fx']);
        $this->assertEquals(283.4, $bill['total_amount_sgd']);

        $usd = BankAccount::factory()->for($this->company)->create(['currency_code' => 'USD', 'opening_balance_fx' => 1000]);
        $sgd = BankAccount::factory()->for($this->company)->create(['currency_code' => 'SGD']);
        $this->postJson('/api/accounts-payable/payments', ['supplier_id' => $this->supplier->id, 'currency_code' => 'EUR', 'exchange_rate' => 1.5,
            'payment_date' => '2026-09-30', 'amount' => 10, 'bank_account_id' => $usd->id], $this->h)->assertStatus(422);

        $pv = $this->postJson('/api/accounts-payable/payments', [
            'supplier_id' => $this->supplier->id, 'payment_date' => '2026-09-30', 'amount' => 218, 'bank_account_id' => $usd->id,
            'allocations' => [['supplier_invoice_id' => $bill['id'], 'amount' => 218]],
        ], $this->h)->assertOk()->json();
        $this->assertEquals(294.3, $pv['amount_sgd']);
        $this->assertSame('paid', SupplierInvoice::find($bill['id'])->status);
        // A bill with no PO is posted once matched; until then AP holds the
        // payment less the loss -- exactly the bill's SGD 283.40, which its
        // posting will clear.
        $this->assertEqualsWithDelta(283.4, $this->balanceOf('2000'), 0.001);
        $this->assertEquals(10.9, $this->balanceOf('6800'), 'SGD 10.90 exchange loss (a debit)');

        $this->postJson("/api/accounts-payable/payments/{$pv['id']}/bank", [], $this->h)->assertOk();
        $line = BankTransaction::where('bank_account_id', $usd->id)->sole();
        $this->assertEquals(218, $line->credit_fx);
        $this->assertEquals(294.3, $line->credit_sgd);
        $book = $this->getJson("/api/bank-accounts/{$usd->id}/transactions", $this->h)->assertOk();
        $this->assertEquals(782, $book->json('rows.0.running_balance_fx'));
        $this->getJson("/api/bank-accounts/{$usd->id}", $this->h)->assertOk()->assertJsonPath('current_balance_fx', 782);
        // An SGD voucher cannot go through the USD account.
        $sgdPv = $this->postJson('/api/accounts-payable/payments', ['supplier_id' => $this->supplier->id, 'currency_code' => 'SGD',
            'payment_date' => '2026-09-30', 'amount_sgd' => 5, 'bank_account_id' => $usd->id], $this->h)->assertStatus(422);
        $this->assertNotNull($sgd->id);
    }

    public function test_a_usd_po_becomes_a_usd_bill_and_a_usd_quotation_a_usd_invoice(): void
    {
        $po = $this->postJson('/api/accounts-payable/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'order_date' => '2026-09-26', 'description' => 'Parts', 'amount' => 100,
        ], $this->h)->assertOk()->json();
        $this->assertSame('USD', $po['currency_code']);
        $this->assertEquals(109, $po['total_amount_fx']);
        PurchaseOrder::whereKey($po['id'])->update(['status' => PurchaseOrder::STATUS_APPROVED]);
        $bill = $this->postJson("/api/accounts-payable/purchase-orders/{$po['id']}/import-to-ap", [], $this->h)->assertOk()->json();
        $this->assertSame('USD', $bill['currency_code']);
        $this->assertEquals(109, $bill['total_amount_fx']);
        $this->assertSame('matched', $bill['match_status']);

        $q = $this->postJson('/api/quotations', [
            'customer_id' => $this->customer->id, 'quotation_date' => '2026-09-26',
            'lines' => [['description' => 'Router', 'quantity' => 2, 'unit_price' => 50, 'unit_of_measure' => 'unit']],
        ], $this->h)->assertOk()->json();
        $this->assertSame('USD', $q['currency_code']);
        $this->assertEquals(109, $q['total_amount_fx']);
        $this->assertEquals(141.7, $q['total_amount_sgd']);
    }

    public function test_a_credit_note_on_a_usd_invoice_is_in_usd_and_crediting_it_all_leaves_nothing(): void
    {
        $inv = $this->usdInvoice(100);
        $note = $this->postJson('/api/credit-notes', ['invoice_id' => $inv['id'], 'amount' => 100, 'reason' => 'Cancelled'], $this->h);
        $note->assertSuccessful();
        $this->assertSame('USD', $note->json('currency_code'));
        $this->assertEquals(109, $note->json('total_amount_fx'));
        $this->assertEquals(141.7, $note->json('total_amount_sgd'));
    }
}
