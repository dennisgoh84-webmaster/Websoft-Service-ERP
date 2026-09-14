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
}
