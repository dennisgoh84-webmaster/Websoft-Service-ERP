<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\SupplierPayment;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Credit Note follow-ups (Dennis, 2026-09-26, decision page): whole lines
 * or part quantities with goods returned to stock; issued straight away
 * within the customer's limit; a paid invoice can be credited, leaving
 * credit on the customer's account to set against another invoice or
 * refund with a Payment Voucher.
 */
class CreditNoteFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private array $h;

    private CompanyIndividual $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $this->h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token')];
        $this->customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'ACME', 'credit_note_approval_limit_sgd' => 100000]);
        TaxCode::create(['company_id' => $this->company->id, 'code' => 'SR', 'name' => 'SR', 'rate_percent' => 9, 'is_active' => true]);
    }

    private function balance(string $code): float
    {
        $id = Account::where('company_id', $this->company->id)->where('code', $code)->value('id');

        return (float) JournalLine::where('account_id', $id)->sum('debit_sgd') - (float) JournalLine::where('account_id', $id)->sum('credit_sgd');
    }

    private function serviceInvoice(float $price): array
    {
        return $this->postJson('/api/invoices', ['customer_id' => $this->customer->id,
            'lines' => [['description' => 'Support', 'quantity' => 1, 'unit_price' => $price]]], $this->h)->assertCreated()->json();
    }

    /** A receipt for the whole invoice, recorded (and posted) as Finance would. */
    private function pay(array $invoice): void
    {
        $bank = BankAccount::factory()->for($this->company)->create();
        $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $this->customer->id, 'payment_date' => now()->toDateString(), 'amount' => $invoice['total_amount_sgd'],
            'bank_account_id' => $bank->id, 'allocations' => [['invoice_id' => $invoice['id'], 'amount' => $invoice['total_amount_sgd']]],
        ], $this->h)->assertOk();
    }

    public function test_part_quantities_with_goods_returned_go_back_into_stock_at_the_cost_they_left_at(): void
    {
        $warehouse = Warehouse::factory()->for($this->company)->create();
        $item = StockItem::factory()->for($this->company)->create();
        InventoryService::receiveStock($this->company->id, $item->id, $warehouse->id, 10, '40.0000', referenceType: 'test', referenceId: (string) Str::orderedUuid());
        $inv = $this->postJson('/api/invoices', ['customer_id' => $this->customer->id, 'lines' => [
            ['description' => 'Switch', 'quantity' => 4, 'unit_price' => 100, 'stock_item_id' => $item->id, 'warehouse_id' => $warehouse->id],
            ['description' => 'Setup', 'quantity' => 1, 'unit_price' => 50],
        ]], $this->h)->assertCreated()->json();
        $this->assertSame(6, (int) StockLevel::where('stock_item_id', $item->id)->value('quantity'));
        $switchLine = collect($inv['lines'])->firstWhere('description', 'Switch');

        $cn = $this->postJson('/api/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'Two faulty', 'lines' => [
            ['invoice_line_id' => $switchLine['id'], 'quantity' => 2, 'return_to_stock' => true],
        ]], $this->h)->assertOk()->assertJson(['status' => 'issued', 'amount_sgd' => 200, 'gst_amount_sgd' => 18, 'total_amount_sgd' => 218]);
        $this->assertSame(8, (int) StockLevel::where('stock_item_id', $item->id)->value('quantity'), 'two came back');
        $this->assertEquals(40, (float) $item->fresh()->avg_cost, 'at the cost they left at');
        $this->assertCount(1, $cn->json('lines'));

        // No more than was sold can be credited, and a service line has no goods to return.
        $this->postJson('/api/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'x', 'lines' => [
            ['invoice_line_id' => $switchLine['id'], 'quantity' => 3],
        ]], $this->h)->assertStatus(422)->assertJsonFragment(['detail' => '"Switch": only 2 can still be credited.']);
        $setup = collect($inv['lines'])->firstWhere('description', 'Setup');
        $this->postJson('/api/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'x', 'lines' => [
            ['invoice_line_id' => $setup['id'], 'quantity' => 1, 'return_to_stock' => true],
        ]], $this->h)->assertStatus(422);
    }

    public function test_a_paid_invoice_can_be_credited_and_the_credit_set_against_the_next_invoice(): void
    {
        $paid = $this->serviceInvoice(100); // 109.00
        $this->pay($paid);
        $cn = $this->postJson('/api/credit-notes', ['invoice_id' => $paid['id'], 'amount' => 50, 'reason' => 'Goodwill'], $this->h)
            ->assertOk()->assertJson(['status' => 'issued', 'total_amount_sgd' => 54.5, 'unapplied_sgd' => 54.5]);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::find($paid['id'])->status, 'still paid: nothing more is owed on it');

        $next = $this->serviceInvoice(200); // 218.00
        $this->postJson("/api/credit-notes/{$cn->json('id')}/apply", ['invoice_id' => $next['id'], 'amount' => 60], $this->h)
            ->assertStatus(422)->assertJsonFragment(['detail' => "Only SGD 54.50 of {$cn->json('credit_note_number')} is left."]);
        $this->postJson("/api/credit-notes/{$cn->json('id')}/apply", ['invoice_id' => $next['id'], 'amount' => 54.5], $this->h)
            ->assertOk()->assertJson(['unapplied_sgd' => 0]);
        $this->assertEqualsWithDelta(163.5, Invoice::find($next['id'])->outstandingSgd()->toFloat(), 0.001);
        $this->assertEqualsWithDelta(163.5, $this->balance('1100'), 0.001, 'AR: 109 + 218 - 109 paid - 54.50 credited');
    }

    public function test_credit_on_account_can_be_refunded_with_a_payment_voucher(): void
    {
        $paid = $this->serviceInvoice(100);
        $this->pay($paid);
        $cn = $this->postJson('/api/credit-notes', ['invoice_id' => $paid['id'], 'amount' => 100, 'reason' => 'Service cancelled'], $this->h)->assertOk();
        $bank = BankAccount::factory()->for($this->company)->create();

        $r = $this->postJson("/api/credit-notes/{$cn->json('id')}/refund", ['bank_account_id' => $bank->id, 'payment_date' => now()->toDateString()], $this->h)
            ->assertOk()->assertJson(['unapplied_sgd' => 0]);
        $pv = SupplierPayment::where('voucher_number', $r->json('refund_voucher_number'))->sole();
        $this->assertSame('109.00', (string) $pv->amount_sgd);
        $this->assertSame($cn->json('id'), $pv->refund_credit_note_id);
        $this->assertEqualsWithDelta(0, $this->balance('1100'), 0.001, 'the refund clears the customer\'s credit on AR, not AP');
        $this->assertEqualsWithDelta(0, $this->balance('2000'), 0.001);
        $this->postJson("/api/accounts-payable/payments/{$pv->id}/bank", [], $this->h)->assertOk();
        $this->postJson("/api/credit-notes/{$cn->json('id')}/refund", ['bank_account_id' => $bank->id, 'payment_date' => now()->toDateString()], $this->h)->assertStatus(422);
        $this->assertSame(CreditNote::STATUS_ISSUED, CreditNote::find($cn->json('id'))->status);
    }
}
