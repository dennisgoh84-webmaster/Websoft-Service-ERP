<?php

namespace Tests\Feature;

use App\Exceptions\LedgerRuleViolation;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\Ledger, mirroring
 * backend/app/services/ledger.py exactly -- the core double-entry
 * rules (debits must equal credits; a posted voucher is immutable;
 * corrections happen only via reversal). See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function accounts(Company $company): array
    {
        return [
            Account::where('company_id', $company->id)->where('code', '1100')->firstOrFail(),
            Account::where('company_id', $company->id)->where('code', '4000')->firstOrFail(),
        ];
    }

    public function test_a_voucher_needs_at_least_one_line(): void
    {
        $company = Company::factory()->create();

        $this->expectException(LedgerRuleViolation::class);
        Ledger::createJournalEntry($company->id, Carbon::today(), 'Empty', []);
    }

    public function test_a_line_cannot_be_both_debit_and_credit(): void
    {
        $company = Company::factory()->create();
        [$ar] = $this->accounts($company);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::createJournalEntry($company->id, Carbon::today(), 'Bad line', [
            ['account_id' => $ar->id, 'debit_sgd' => 100, 'credit_sgd' => 100],
        ]);
    }

    public function test_a_line_needs_a_debit_or_a_credit(): void
    {
        $company = Company::factory()->create();
        [$ar] = $this->accounts($company);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::createJournalEntry($company->id, Carbon::today(), 'Zero line', [
            ['account_id' => $ar->id],
        ]);
    }

    public function test_posting_an_unbalanced_entry_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Unbalanced', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 90],
        ]);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::postEntry($entry, $actor->id);
    }

    public function test_posting_a_balanced_entry_succeeds(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Balanced', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);

        Ledger::postEntry($entry, $actor->id);

        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->fresh()->status);
    }

    public function test_cannot_post_an_already_posted_voucher(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Balanced', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);
        Ledger::postEntry($entry, $actor->id);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::postEntry($entry->fresh(), $actor->id);
    }

    public function test_reversing_writes_a_mirror_image_entry_and_marks_the_original_reversed(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Balanced', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);
        Ledger::postEntry($entry, $actor->id);

        $reversal = Ledger::reverseEntry($entry->fresh(), $actor->id, 'Wrong amount keyed');

        $this->assertSame(JournalEntry::STATUS_REVERSED, $entry->fresh()->status);
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        // Mirror image: the AR line was a debit, the reversal credits it.
        $arLine = $reversal->lines->firstWhere('account_id', $ar->id);
        $this->assertEqualsWithDelta(100.0, (float) $arLine->credit_sgd, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $arLine->debit_sgd, 0.01);
    }

    public function test_cannot_reverse_a_draft_voucher(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Draft', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::reverseEntry($entry, $actor->id, 'x');
    }

    public function test_reversing_requires_a_reason(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Balanced', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);
        Ledger::postEntry($entry, $actor->id);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::reverseEntry($entry->fresh(), $actor->id, '   ');
    }

    public function test_a_retired_account_cannot_be_posted_to(): void
    {
        $company = Company::factory()->create();
        [$ar, $revenue] = $this->accounts($company);
        $revenue->update(['is_active' => false]);

        $this->expectException(LedgerRuleViolation::class);
        Ledger::createJournalEntry($company->id, Carbon::today(), 'x', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);
    }

    // ── accountBalances() / accountTransactions() ───────────────────
    // Mirrors backend/app/services/ledger.py's account_balances() and
    // account_transactions() -- the trial balance and per-account
    // drill-down ledger built for Accounting Period management / GL
    // Trial Balance (docs/php-conversion-plan.md).

    public function test_account_balances_trial_balance_debits_equal_credits(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $entry = Ledger::createJournalEntry($company->id, Carbon::parse('2026-09-01'), 'Sale', [
            ['account_id' => $ar->id, 'debit_sgd' => 150],
            ['account_id' => $revenue->id, 'credit_sgd' => 150],
        ]);
        Ledger::postEntry($entry, $actor->id);

        $rows = Ledger::accountBalances($company->id);

        $totalDebit = array_reduce($rows, fn ($c, $r) => $c + $r['debit_sgd']->toFloat(), 0.0);
        $totalCredit = array_reduce($rows, fn ($c, $r) => $c + $r['credit_sgd']->toFloat(), 0.0);
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.001);
        $this->assertEqualsWithDelta(150.0, $totalDebit, 0.01);

        $arRow = collect($rows)->firstWhere('account_id', $ar->id);
        $this->assertEqualsWithDelta(150.0, $arRow['balance_sgd']->toFloat(), 0.01);
        $revenueRow = collect($rows)->firstWhere('account_id', $revenue->id);
        $this->assertEqualsWithDelta(-150.0, $revenueRow['balance_sgd']->toFloat(), 0.01);
    }

    public function test_account_balances_excludes_draft_and_reversed_vouchers(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);

        // Draft -- never posted, must not appear.
        Ledger::createJournalEntry($company->id, Carbon::today(), 'Draft only', [
            ['account_id' => $ar->id, 'debit_sgd' => 999],
            ['account_id' => $revenue->id, 'credit_sgd' => 999],
        ]);
        // Posted then reversed -- the REVERSED original is excluded
        // by the status=posted filter entirely (its debit doesn't
        // count), while the reversal itself is a new POSTED voucher
        // and does count -- so only the reversal's mirror-image credit
        // shows up here.
        $entry = Ledger::createJournalEntry($company->id, Carbon::today(), 'Reversed', [
            ['account_id' => $ar->id, 'debit_sgd' => 500],
            ['account_id' => $revenue->id, 'credit_sgd' => 500],
        ]);
        Ledger::postEntry($entry, $actor->id);
        Ledger::reverseEntry($entry->fresh(), $actor->id, 'undo');

        $rows = Ledger::accountBalances($company->id);
        $arRow = collect($rows)->firstWhere('account_id', $ar->id);
        $this->assertEqualsWithDelta(-500.0, $arRow['balance_sgd']->toFloat(), 0.01);
    }

    public function test_account_transactions_running_balance_and_ordering(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $e1 = Ledger::createJournalEntry($company->id, Carbon::parse('2026-09-05'), 'First', [
            ['account_id' => $ar->id, 'debit_sgd' => 100],
            ['account_id' => $revenue->id, 'credit_sgd' => 100],
        ]);
        Ledger::postEntry($e1, $actor->id);
        $e2 = Ledger::createJournalEntry($company->id, Carbon::parse('2026-09-10'), 'Second', [
            ['account_id' => $ar->id, 'debit_sgd' => 50],
            ['account_id' => $revenue->id, 'credit_sgd' => 50],
        ]);
        Ledger::postEntry($e2, $actor->id);

        $rows = Ledger::accountTransactions($company->id, $ar->id);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-05', $rows[0]['entry_date']);
        $this->assertSame('2026-09-10', $rows[1]['entry_date']);
        $this->assertEqualsWithDelta(100.0, $rows[0]['balance_sgd'], 0.01);
        $this->assertEqualsWithDelta(150.0, $rows[1]['balance_sgd'], 0.01);
    }

    public function test_account_transactions_opening_balance_carries_forward_when_date_from_is_set(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        [$ar, $revenue] = $this->accounts($company);
        $e1 = Ledger::createJournalEntry($company->id, Carbon::parse('2026-08-01'), 'Before window', [
            ['account_id' => $ar->id, 'debit_sgd' => 200],
            ['account_id' => $revenue->id, 'credit_sgd' => 200],
        ]);
        Ledger::postEntry($e1, $actor->id);
        $e2 = Ledger::createJournalEntry($company->id, Carbon::parse('2026-09-05'), 'In window', [
            ['account_id' => $ar->id, 'debit_sgd' => 30],
            ['account_id' => $revenue->id, 'credit_sgd' => 30],
        ]);
        Ledger::postEntry($e2, $actor->id);

        $rows = Ledger::accountTransactions($company->id, $ar->id, Carbon::parse('2026-09-01'));

        // Only the in-window line is returned, but its running balance
        // already carries the August opening balance forward.
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(230.0, $rows[0]['balance_sgd'], 0.01);
    }

    public function test_account_transactions_for_unknown_account_or_other_company_returns_empty(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [$ar] = $this->accounts($companyB);

        $this->assertSame([], Ledger::accountTransactions($companyA->id, $ar->id));
        $this->assertSame([], Ledger::accountTransactions($companyA->id, (string) Str::uuid()));
    }
}
