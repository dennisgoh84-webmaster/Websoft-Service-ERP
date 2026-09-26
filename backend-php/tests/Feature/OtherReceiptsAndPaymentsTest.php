<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\DocxForms;
use App\Services\PasswordPolicy;
use App\Services\Posting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank interest, bank charges and the like go through a Receipt or a
 * Payment Voucher against a GL account ("Other"), never straight into
 * the Bank Book (Dennis, 2026-09-26, open-business-decisions.md #49 /
 * 31.1), so every bank line has its ledger entry.
 */
class OtherReceiptsAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private BankAccount $bank;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
        $this->headers = ['Authorization' => "Bearer {$token}"];
        $cashAtBank = Account::where('company_id', $this->company->id)->where('code', '1000')->first();
        $this->bank = BankAccount::factory()->for($this->company)->create(['gl_account_id' => $cashAtBank->id]);
    }

    private function account(string $code, string $name, string $type): Account
    {
        return Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'account_type' => $type, 'is_active' => true]);
    }

    /** [account code => [debit, credit]] of a voucher's live GL entry. */
    private function glLines(string $sourceType, string $id): array
    {
        $out = [];
        foreach (Posting::liveEntryFor($sourceType, $id)->lines as $line) {
            $out[Account::find($line->account_id)->code] = [(float) $line->debit_sgd, (float) $line->credit_sgd];
        }

        return $out;
    }

    public function test_bank_interest_is_an_other_receipt_credited_to_its_account_and_banked(): void
    {
        $interest = $this->account('4910', 'Interest income', Account::TYPE_REVENUE);

        $rv = $this->postJson('/api/accounts-receivable/payments', [
            'gl_account_id' => $interest->id, 'notes' => 'DBS interest for September',
            'payment_date' => '2026-09-30', 'amount_sgd' => 12.34, 'bank_account_id' => $this->bank->id,
        ], $this->headers)->assertOk();

        $rv->assertJson(['kind' => 'other', 'customer_id' => null, 'gl_account' => '4910 Interest income', 'unallocated_sgd' => 0, 'gl_status' => 'posted']);
        $this->assertSame(['1000' => [12.34, 0.0], '4910' => [0.0, 12.34]], $this->glLines(Posting::SOURCE_RECEIPT, $rv->json('id')));

        // It settles no invoices.
        $this->postJson("/api/accounts-receivable/payments/{$rv->json('id')}/allocate", [
            'allocations' => [['invoice_id' => $rv->json('id'), 'amount_sgd' => 1]],
        ], $this->headers)->assertStatus(422);

        // The Bank step puts it in the Bank Book, money in, with its description.
        $this->postJson("/api/accounts-receivable/payments/{$rv->json('id')}/bank", [], $this->headers)->assertOk();
        $line = BankTransaction::where('bank_account_id', $this->bank->id)->sole();
        $this->assertEqualsWithDelta(12.34, (float) $line->debit_sgd, 0.001);
        $this->assertStringContainsString('DBS interest for September', $line->description);

        // Its Word receipt says what it was for, in place of a Company / Individual.
        $this->get("/api/accounts-receivable/payments/{$rv->json('id')}/export.docx", $this->headers)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'rv');
        file_put_contents($path, DocxForms::receiptToDocx(Payment::find($rv->json('id')), null, $this->company, []));
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString('DBS interest for September', (string) $zip->getFromName('word/document.xml'));
        $this->assertStringContainsString('4910 Interest income', (string) $zip->getFromName('word/document.xml'));
    }

    public function test_bank_charges_are_an_other_payment_debited_to_their_account_and_banked(): void
    {
        $charges = $this->account('6500', 'Bank charges', Account::TYPE_EXPENSE);

        $pv = $this->postJson('/api/accounts-payable/payments', [
            'gl_account_id' => $charges->id, 'notes' => 'DBS service charge September',
            'payment_date' => '2026-09-30', 'amount_sgd' => 25, 'bank_account_id' => $this->bank->id,
        ], $this->headers)->assertOk();

        $pv->assertJson(['kind' => 'other', 'supplier_id' => null, 'gl_account' => '6500 Bank charges', 'unallocated_sgd' => 0, 'notes' => 'DBS service charge September']);
        $this->assertSame(['6500' => [25.0, 0.0], '1000' => [0.0, 25.0]], $this->glLines(Posting::SOURCE_SUPPLIER_PAYMENT, $pv->json('id')));

        $this->postJson("/api/accounts-payable/payments/{$pv->json('id')}/bank", [], $this->headers)->assertOk();
        $line = BankTransaction::where('bank_account_id', $this->bank->id)->sole();
        $this->assertEqualsWithDelta(25.0, (float) $line->credit_sgd, 0.001);

        // UNGL reverses it like any other payment voucher (after unbanking).
        $this->postJson("/api/accounts-payable/payments/{$pv->json('id')}/unbank", ['reason' => 'Wrong month'], $this->headers)->assertOk();
        $this->postJson("/api/accounts-payable/payments/{$pv->json('id')}/ungl", ['reason' => 'Wrong month'], $this->headers)->assertOk();
        $this->assertNull(Posting::liveEntryFor(Posting::SOURCE_SUPPLIER_PAYMENT, $pv->json('id')));
    }

    public function test_what_an_other_voucher_may_not_be_against(): void
    {
        $post = fn (string $path, array $body) => $this->postJson($path, $body + [
            'payment_date' => '2026-09-30', 'amount_sgd' => 10, 'bank_account_id' => $this->bank->id, 'notes' => 'x',
        ], $this->headers);
        $code = fn (string $c) => Account::where('company_id', $this->company->id)->where('code', $c)->value('id');

        // Control accounts: their balances come only from their documents.
        foreach (['1100', '2000', '2100', '2110'] as $control) {
            $post('/api/accounts-receivable/payments', ['gl_account_id' => $code($control)])->assertStatus(422);
            $post('/api/accounts-payable/payments', ['gl_account_id' => $code($control)])->assertStatus(422);
        }
        // A bank's own account: that would be a transfer.
        $post('/api/accounts-payable/payments', ['gl_account_id' => $code('1000')])->assertStatus(422);
        // Another company's account.
        $theirs = Account::create(['company_id' => Company::factory()->create()->id, 'code' => '6501', 'name' => 'x', 'account_type' => Account::TYPE_EXPENSE, 'is_active' => true]);
        $post('/api/accounts-payable/payments', ['gl_account_id' => $theirs->id])->assertStatus(422);

        $charges = $this->account('6500', 'Bank charges', Account::TYPE_EXPENSE);
        // It needs a description of what it is.
        $this->postJson('/api/accounts-payable/payments', [
            'gl_account_id' => $charges->id, 'payment_date' => '2026-09-30', 'amount_sgd' => 10, 'bank_account_id' => $this->bank->id,
        ], $this->headers)->assertStatus(422);
        // A supplier and an account at once is refused.
        $supplier = CompanyIndividual::factory()->for($this->company)->create(['is_supplier' => true]);
        $post('/api/accounts-payable/payments', ['gl_account_id' => $charges->id, 'supplier_id' => $supplier->id])->assertStatus(422);
        // Neither is refused.
        $post('/api/accounts-payable/payments', [])->assertStatus(422);
    }

    public function test_the_database_holds_exactly_one_of_party_or_account(): void
    {
        $this->expectException(QueryException::class);
        SupplierPayment::create([
            'company_id' => $this->company->id, 'voucher_number' => 'PV-X', 'payment_date' => '2026-09-30',
            'amount_sgd' => 1, 'bank_account_id' => $this->bank->id,
        ]);
    }
}
