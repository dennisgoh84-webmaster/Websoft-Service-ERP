<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\BankTransactionController and
 * App\Services\BankBook -- converted from
 * backend/app/routers/bank_transactions.py and services/bank_book.py.
 */
class BankTransactionTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'finance_accounting';

    private Company $company;

    private BankAccount $bank;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->token = $this->ownerToken($this->company);
        $this->bank = BankAccount::create([
            'company_id' => $this->company->id,
            'bank_name' => 'DBS', 'account_name' => 'Operating', 'account_number' => '001-2345',
            'opening_balance_sgd' => '1000.00', 'opening_balance_date' => '2026-01-01',
        ]);
    }

    private function enableModule(Company $company, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company);

        return $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    /**
     * A Bank Book line. Keying one in directly is refused since
     * 2026-09-26 (#49 / 31.1), so these stand for lines keyed before
     * then, which stay and can still be voided and reconciled.
     */
    private function addLine(array $attrs): array
    {
        static $n = 0;
        $n++;

        return BankTransaction::create(array_merge([
            'company_id' => $this->company->id, 'bank_account_id' => $this->bank->id,
            'transaction_number' => sprintf('BT-2026-%04d', $n),
            'transaction_date' => '2026-02-01', 'description' => 'A line',
            'debit_sgd' => '0.00', 'credit_sgd' => '0.00',
        ], array_map(fn ($v) => is_int($v) || is_float($v) ? number_format($v, 2, '.', '') : $v, $attrs)))->toArray();
    }

    public function test_the_ledger_runs_a_balance_from_the_opening_balance(): void
    {
        $this->addLine(['transaction_date' => '2026-02-01', 'description' => 'Receipt', 'debit_sgd' => 500]);
        $this->addLine(['transaction_date' => '2026-02-02', 'description' => 'Payment', 'credit_sgd' => 200]);

        $ledger = $this->getJson("/api/bank-accounts/{$this->bank->id}/transactions", $this->headers())
            ->assertOk()->json();

        $this->assertEqualsWithDelta(1000.0, $ledger['opening_balance_sgd'], 0.01);
        // 1000 + 500 = 1500, then -200 = 1300.
        $this->assertEqualsWithDelta(1500.0, $ledger['rows'][0]['running_balance_sgd'], 0.01);
        $this->assertEqualsWithDelta(1300.0, $ledger['rows'][1]['running_balance_sgd'], 0.01);
        $this->assertEqualsWithDelta(1300.0, $ledger['closing_balance_sgd'], 0.01);
        $this->assertSame(2, $ledger['unreconciled_count']);
    }

    /** #49 / 31.1: money in and out is keyed as a Receipt or Payment Voucher, never straight into the Bank Book. */
    public function test_keying_a_line_directly_is_refused(): void
    {
        $this->postJson("/api/bank-accounts/{$this->bank->id}/transactions", [
            'transaction_date' => '2026-02-01', 'description' => 'Bank charges', 'credit_sgd' => 15,
        ], $this->headers())->assertStatus(422)
            ->assertJsonPath('detail', fn ($d) => str_contains($d, 'not keyed in directly'));

        $this->assertSame(0, BankTransaction::where('bank_account_id', $this->bank->id)->count());
    }

    public function test_a_voided_line_stays_visible_but_stops_moving_the_balance(): void
    {
        $line = $this->addLine(['description' => 'Wrong entry', 'debit_sgd' => 500]);

        $this->postJson("/api/bank-transactions/{$line['id']}/void", ['reason' => 'Keyed twice'], $this->headers())
            ->assertOk()
            ->assertJsonPath('is_voided', true)
            ->assertJsonPath('void_reason', 'Keyed twice');

        $ledger = $this->getJson("/api/bank-accounts/{$this->bank->id}/transactions", $this->headers())->json();
        // CLAUDE.md forbids deleting financial records: the line is
        // still in the ledger, it just no longer counts.
        $this->assertCount(1, $ledger['rows']);
        $this->assertTrue($ledger['rows'][0]['is_voided']);
        $this->assertEqualsWithDelta(1000.0, $ledger['closing_balance_sgd'], 0.01);
        $this->assertSame(0, $ledger['unreconciled_count'], 'a voided line is not an unreconciled one');
    }

    public function test_voiding_twice_is_refused(): void
    {
        $line = $this->addLine(['debit_sgd' => 100]);
        $this->postJson("/api/bank-transactions/{$line['id']}/void", ['reason' => 'First'], $this->headers())->assertOk();
        $this->postJson("/api/bank-transactions/{$line['id']}/void", ['reason' => 'Again'], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('detail', 'That transaction is already voided.');
    }

    public function test_toggle_reconciled_flips_both_ways(): void
    {
        $line = $this->addLine(['debit_sgd' => 100]);

        $this->postJson("/api/bank-transactions/{$line['id']}/toggle-reconciled", [], $this->headers())
            ->assertOk()->assertJsonPath('is_reconciled', true);
        $this->assertNotNull(BankTransaction::find($line['id'])->reconciled_at);

        $this->postJson("/api/bank-transactions/{$line['id']}/toggle-reconciled", [], $this->headers())
            ->assertOk()->assertJsonPath('is_reconciled', false)->assertJsonPath('reconciled_at', null);
    }

    public function test_reconciled_balance_counts_only_ticked_lines(): void
    {
        $ticked = $this->addLine(['description' => 'Cleared', 'debit_sgd' => 500]);
        $this->addLine(['description' => 'Not yet cleared', 'debit_sgd' => 300]);
        $this->postJson("/api/bank-transactions/{$ticked['id']}/toggle-reconciled", [], $this->headers())->assertOk();

        $ledger = $this->getJson("/api/bank-accounts/{$this->bank->id}/transactions", $this->headers())->json();
        $this->assertEqualsWithDelta(1800.0, $ledger['closing_balance_sgd'], 0.01);
        // Opening 1000 + only the 500 that has cleared.
        $this->assertEqualsWithDelta(1500.0, $ledger['reconciled_balance_sgd'], 0.01);
        $this->assertSame(1, $ledger['unreconciled_count']);
    }

    public function test_a_reconciliation_session_ticks_lines_and_snapshots_the_difference(): void
    {
        $a = $this->addLine(['transaction_date' => '2026-02-01', 'description' => 'One', 'debit_sgd' => 500]);
        // Dated AFTER the statement, so it must not count toward the
        // ledger balance as at the statement date.
        $this->addLine(['transaction_date' => '2026-03-15', 'description' => 'Later', 'debit_sgd' => 999]);

        $result = $this->postJson("/api/bank-accounts/{$this->bank->id}/reconciliations", [
            'statement_date' => '2026-02-28',
            'statement_balance_sgd' => 1450.00,
            'note' => 'Feb statement',
            'reconciled_transaction_ids' => [$a['id']],
        ], $this->headers())->assertOk()->json();

        // Ledger as at 2026-02-28 = 1000 + 500 = 1500. Statement says
        // 1450, so the difference is -50 and is on permanent record.
        $this->assertEqualsWithDelta(1500.0, $result['ledger_balance_sgd'], 0.01);
        $this->assertEqualsWithDelta(1450.0, $result['statement_balance_sgd'], 0.01);
        $this->assertEqualsWithDelta(-50.0, $result['difference_sgd'], 0.01);
        $this->assertNotNull($result['reconciled_by_name']);

        $this->assertTrue(BankTransaction::find($a['id'])->is_reconciled);

        $this->getJson("/api/bank-accounts/{$this->bank->id}/reconciliations", $this->headers())
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.note', 'Feb statement');
    }

    public function test_reconciling_a_transaction_from_another_account_is_refused(): void
    {
        $otherBank = BankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'OCBC',
            'account_name' => 'Second', 'account_number' => '999', 'opening_balance_sgd' => '0.00',
        ]);
        $foreign = BankTransaction::create([
            'company_id' => $this->company->id, 'bank_account_id' => $otherBank->id,
            'transaction_number' => 'BT-X', 'transaction_date' => '2026-02-01',
            'description' => 'Other account', 'debit_sgd' => '10.00', 'credit_sgd' => '0.00',
        ]);

        $this->postJson("/api/bank-accounts/{$this->bank->id}/reconciliations", [
            'statement_date' => '2026-02-28', 'statement_balance_sgd' => 1000,
            'reconciled_transaction_ids' => [$foreign->id],
        ], $this->headers())->assertStatus(400)
            ->assertJsonPath('detail', 'One of the transactions to reconcile was not found.');

        $this->assertFalse($foreign->fresh()->is_reconciled);
    }

    public function test_another_companys_bank_account_and_transaction_are_not_found(): void
    {
        $other = Company::factory()->create();
        $theirBank = BankAccount::create([
            'company_id' => $other->id, 'bank_name' => 'UOB',
            'account_name' => 'Theirs', 'account_number' => '777', 'opening_balance_sgd' => '0.00',
        ]);

        $this->getJson("/api/bank-accounts/{$theirBank->id}/transactions", $this->headers())->assertStatus(404);
        $this->postJson("/api/bank-accounts/{$theirBank->id}/transactions", [
            'transaction_date' => '2026-02-01', 'description' => 'Nope', 'debit_sgd' => 1,
        ], $this->headers())->assertStatus(404);
    }

    public function test_the_account_list_balance_matches_the_ledger_closing_balance(): void
    {
        // Both read App\Services\BankBook, so they cannot drift.
        $this->addLine(['debit_sgd' => 250]);
        $this->addLine(['credit_sgd' => 75]);

        $ledger = $this->getJson("/api/bank-accounts/{$this->bank->id}/transactions", $this->headers())->json();
        $list = $this->getJson('/api/bank-accounts', $this->headers())->assertOk()->json();
        $row = collect($list)->firstWhere('id', $this->bank->id);

        $this->assertEqualsWithDelta($ledger['closing_balance_sgd'], $row['current_balance_sgd'], 0.001);
        $this->assertEqualsWithDelta(1175.0, $row['current_balance_sgd'], 0.01);
    }

    public function test_view_level_can_read_but_not_write(): void
    {
        $this->enableModule($this->company);
        $group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::VIEW,
        ]);
        $user = User::factory()->for($this->company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->getJson("/api/bank-accounts/{$this->bank->id}/transactions", $this->headers($token))->assertOk();
        $this->postJson("/api/bank-accounts/{$this->bank->id}/transactions", [
            'transaction_date' => '2026-02-01', 'description' => 'No', 'debit_sgd' => 1,
        ], $this->headers($token))->assertStatus(403);
    }
}
