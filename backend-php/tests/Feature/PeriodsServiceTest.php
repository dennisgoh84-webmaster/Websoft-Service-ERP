<?php

namespace Tests\Feature;

use App\Exceptions\PeriodLockedError;
use App\Exceptions\YearEndClosingError;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\FiscalYearClosure;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\Periods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\Periods, mirroring
 * backend/app/services/periods.py exactly -- the lock matrix
 * (VALID_DOC_OPERATIONS), the opt-in-protection default, the derived
 * open/closed status, and the Year-End Closing preconditions/
 * arithmetic. See docs/php-conversion-plan.md's "after converting
 * each module" checklist.
 */
class PeriodsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function period(Company $company, string $start, string $end, int $fiscalYear = 2026): AccountingPeriod
    {
        $period = AccountingPeriod::create([
            'company_id' => $company->id,
            'fiscal_year' => $fiscalYear,
            'name' => "{$start} to {$end}",
            'period_start' => $start,
            'period_end' => $end,
        ]);
        Periods::seedLocksForPeriod($period, false);

        return $period->fresh('locks');
    }

    // ── Lock matrix / seeding ─────────────────────────────────────

    public function test_seeding_creates_one_lock_row_per_valid_doc_operation_combination(): void
    {
        $company = Company::factory()->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');

        $expected = array_sum(array_map('count', Periods::VALID_DOC_OPERATIONS));
        $this->assertSame($expected, $period->locks->count());
        $this->assertTrue($period->locks->every(fn (PeriodLock $lk) => ! $lk->is_locked));
    }

    public function test_toggle_lock_rejects_an_operation_not_valid_for_the_doc_type(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');

        // BANK is valid for receipt_voucher/payment_voucher but not
        // for sales_invoice -- see VALID_DOC_OPERATIONS.
        $this->expectException(\InvalidArgumentException::class);
        Periods::toggleLock($period, Periods::DOC_SALES_INVOICE, Periods::OP_BANK, true, $actor->id);
    }

    // ── requireAllows: opt-in protection + enforcement ──────────────

    public function test_a_date_with_no_period_defined_is_unrestricted(): void
    {
        $company = Company::factory()->create();

        // No exception -- opt-in protection.
        Periods::requireAllows($company->id, Carbon::parse('2026-09-15'), Periods::DOC_SALES_INVOICE, Periods::OP_GL);
        $this->addToAssertionCount(1);
    }

    public function test_require_allows_permits_an_operation_that_is_not_locked(): void
    {
        $company = Company::factory()->create();
        $this->period($company, '2026-09-01', '2026-09-30');

        Periods::requireAllows($company->id, Carbon::parse('2026-09-15'), Periods::DOC_SALES_INVOICE, Periods::OP_GL);
        $this->addToAssertionCount(1);
    }

    public function test_require_allows_throws_when_the_specific_cell_is_locked(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');
        Periods::toggleLock($period, Periods::DOC_SALES_INVOICE, Periods::OP_GL, true, $actor->id);

        $this->expectException(PeriodLockedError::class);
        Periods::requireAllows($company->id, Carbon::parse('2026-09-15'), Periods::DOC_SALES_INVOICE, Periods::OP_GL);
    }

    public function test_require_allows_only_blocks_the_locked_doc_type_operation_pair(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');
        Periods::toggleLock($period, Periods::DOC_SALES_INVOICE, Periods::OP_GL, true, $actor->id);

        // A different operation on the same doc type is unaffected...
        Periods::requireAllows($company->id, Carbon::parse('2026-09-15'), Periods::DOC_SALES_INVOICE, Periods::OP_REVERSE);
        // ...as is the same operation on a different doc type.
        Periods::requireAllows($company->id, Carbon::parse('2026-09-15'), Periods::DOC_JOURNAL_VOUCHER, Periods::OP_GL);
        $this->addToAssertionCount(2);
    }

    // ── Derived status ───────────────────────────────────────────

    public function test_locking_every_cell_derives_closed_status_and_stamps_who_closed_it(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');

        Periods::setAllLocks($period, true, $actor->id);

        $fresh = $period->fresh('locks');
        $this->assertSame(AccountingPeriod::STATUS_CLOSED, $fresh->status);
        $this->assertSame($actor->id, $fresh->closed_by_user_id);
        $this->assertNotNull($fresh->closed_at);
        $this->assertTrue($fresh->locks->every(fn (PeriodLock $lk) => $lk->is_locked));
    }

    public function test_a_partially_locked_period_still_shows_as_open(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');

        Periods::toggleLock($period, Periods::DOC_SALES_INVOICE, Periods::OP_GL, true, $actor->id);

        $fresh = $period->fresh('locks');
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $fresh->status);
        $this->assertSame(1, $fresh->locks->where('is_locked', true)->count());
    }

    public function test_reopening_clears_every_lock_and_the_closed_metadata(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-09-01', '2026-09-30');
        Periods::setAllLocks($period, true, $actor->id);

        Periods::reopenPeriod($period->fresh('locks'));

        $fresh = $period->fresh('locks');
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $fresh->status);
        $this->assertNull($fresh->closed_by_user_id);
        $this->assertNull($fresh->closed_at);
        $this->assertTrue($fresh->locks->every(fn (PeriodLock $lk) => ! $lk->is_locked));
    }

    // ── Year-End Closing preconditions ──────────────────────────────

    public function test_closing_a_fiscal_year_with_no_periods_defined_is_rejected(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $equity = Account::where('company_id', $company->id)->where('code', '3000')->first()
            ?? Account::create(['company_id' => $company->id, 'code' => '3999', 'name' => 'Equity', 'account_type' => Account::TYPE_EQUITY]);

        $this->expectException(YearEndClosingError::class);
        $this->expectExceptionMessage('No accounting periods are defined for fiscal year 2026.');
        Periods::closeFiscalYear($company->id, 2026, $equity->id, $actor->id);
    }

    public function test_closing_a_fiscal_year_requires_every_period_in_it_to_already_be_closed(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $equity = Account::create(['company_id' => $company->id, 'code' => '3999', 'name' => 'Equity', 'account_type' => Account::TYPE_EQUITY]);
        $p1 = $this->period($company, '2026-01-01', '2026-01-31');
        $this->period($company, '2026-02-01', '2026-02-28');
        Periods::setAllLocks($p1, true, $actor->id); // only one of the two is closed

        $this->expectException(YearEndClosingError::class);
        $this->expectExceptionMessage('must be closed (all operations locked) before year-end closing');
        Periods::closeFiscalYear($company->id, 2026, $equity->id, $actor->id);
    }

    public function test_retained_earnings_account_must_be_an_equity_account(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-01-01', '2026-01-31');
        Periods::setAllLocks($period, true, $actor->id);
        $revenueAccount = Account::where('company_id', $company->id)->where('code', '4000')->firstOrFail();

        $this->expectException(YearEndClosingError::class);
        $this->expectExceptionMessage('is not an Equity account.');
        Periods::closeFiscalYear($company->id, 2026, $revenueAccount->id, $actor->id);
    }

    public function test_a_fiscal_year_with_no_revenue_or_expense_activity_has_nothing_to_close(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $period = $this->period($company, '2026-01-01', '2026-01-31');
        Periods::setAllLocks($period, true, $actor->id);
        $equity = Account::create(['company_id' => $company->id, 'code' => '3999', 'name' => 'Equity', 'account_type' => Account::TYPE_EQUITY]);

        $this->expectException(YearEndClosingError::class);
        $this->expectExceptionMessage('nothing to close');
        Periods::closeFiscalYear($company->id, 2026, $equity->id, $actor->id);
    }

    public function test_closing_the_same_fiscal_year_twice_is_rejected(): void
    {
        [, , , $company, $actor, $equity] = $this->closeAFiscalYearWithRevenue();

        $this->expectException(YearEndClosingError::class);
        $this->expectExceptionMessage('Fiscal year 2026 has already been closed.');
        Periods::closeFiscalYear($company->id, 2026, $equity->id, $actor->id);
    }

    /**
     * Full happy path: SGD 1,000 of contract revenue is posted (via
     * BillingService::issueContractAnnualInvoice, Cr revenue 1000 /
     * Cr GST output 90 / Dr AR 1090), the covering period is closed,
     * and Year-End Closing zeroes the revenue account's credit balance
     * into Retained Earnings with one balanced journal entry --
     * pinning the exact worked example.
     *
     * @return array{0: JournalEntry, 1: FiscalYearClosure, 2: Account, 3: Company, 4: User, 5: Account}
     */
    private function closeAFiscalYearWithRevenue(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->for($company)->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $today = Carbon::today();
        $period = $this->period($company, $today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString());

        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: $today->toDateString(), actorUserId: $actor->id,
        );
        BillingService::issueContractAnnualInvoice($contract, $actor->id);

        Periods::setAllLocks($period->fresh(), true, $actor->id);
        $equity = Account::create(['company_id' => $company->id, 'code' => '3100', 'name' => 'Retained earnings', 'account_type' => Account::TYPE_EQUITY]);

        $entry = Periods::closeFiscalYear($company->id, $today->year, $equity->id, $actor->id);
        $closure = FiscalYearClosure::where('company_id', $company->id)->where('fiscal_year', $today->year)->firstOrFail();

        return [$entry, $closure, $equity, $company, $actor, $equity];
    }

    public function test_closing_a_fiscal_year_posts_one_balanced_entry_zeroing_revenue_to_equity(): void
    {
        [$entry, $closure, $equity, $company] = $this->closeAFiscalYearWithRevenue();

        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertEqualsWithDelta((float) $entry->totalDebit()->toFloat(), (float) $entry->totalCredit()->toFloat(), 0.001);

        $revenue = Account::where('company_id', $company->id)->where('code', '4000')->firstOrFail();
        $revenueLine = $entry->lines->firstWhere('account_id', $revenue->id);
        $equityLine = $entry->lines->firstWhere('account_id', $equity->id);

        // The revenue account had a 1000 credit balance -- closing it
        // debits revenue by 1000 (zeroing it) and credits equity by
        // 1000 (a net profit for the year).
        $this->assertEqualsWithDelta(1000.0, (float) $revenueLine->debit_sgd, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $revenueLine->credit_sgd, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $equityLine->credit_sgd, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $equityLine->debit_sgd, 0.01);

        $this->assertSame($entry->id, $closure->closing_journal_entry_id);
        $this->assertSame($equity->id, $closure->retained_earnings_account_id);
    }

    public function test_closing_a_fiscal_year_bypasses_its_own_periods_gl_lock(): void
    {
        // Close All also locks JOURNAL_VOUCHER/GL in the covering
        // period, so posting the closing entry itself would be
        // blocked unless Ledger::postEntry's bypassPeriodCheck is
        // honoured for it (mirrors Python's bypass_period_check=True).
        [$entry] = $this->closeAFiscalYearWithRevenue();
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
    }
}
