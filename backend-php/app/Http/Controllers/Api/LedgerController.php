<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\LedgerRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Models\GroupModuleAuthority;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * General Ledger reporting: the trial balance and the per-account
 * transaction ledger (drill-down). Mirrors the trial-balance and
 * account-transactions halves of backend/app/routers/ledger.py -- see
 * that file's docstring: a Journal Voucher is the manual double
 * entry, and the posting rules for automatic entries live in
 * App\Services\Posting instead.
 *
 * Journal Voucher CRUD landed 2026-09-15, closing the KNOWN GAP this
 * docblock previously recorded: list/get/create/post/reverse a manual
 * voucher from the General Ledger screen, plus CSV/Excel export for
 * the voucher list, the trial balance and the account ledger.
 *
 * App\Services\Ledger already had createJournalEntry()/postEntry()/
 * reverseEntry(), built for the GL posting + Bank module and used
 * internally by App\Services\Posting -- so this adds the endpoints a
 * person drives by hand, not the posting rules, which stay in one
 * place for both automatic and manual entries.
 *
 * REVERSAL IS NEVER A DELETE: the original voucher stays exactly as it
 * was and a mirror entry is written, so the mistake and its correction
 * both remain on record (CLAUDE.md forbids deleting financial
 * records).
 */
class LedgerController extends Controller
{
    private const MODULE = 'finance_accounting';

    /** @var array<int, string> */
    private const VOUCHER_EXPORT_FIELDS = [
        'voucher_number', 'voucher_type', 'entry_date', 'narration', 'status',
        'total_debit_sgd', 'total_credit_sgd',
    ];

    /** @var array<int, string> */
    private const TRIAL_BALANCE_EXPORT_FIELDS = [
        'code', 'name', 'account_type', 'debit_sgd', 'credit_sgd', 'balance_sgd',
    ];

    // ── Journal Vouchers ────────────────────────────────────────────

    public function listVouchers(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);

        return response()->json(
            $this->filteredVouchers($user->company_id, $request)
                ->map(fn (JournalEntry $e) => $this->voucherOut($e))
        );
    }

    public function getVoucher(Request $request, string $entryId)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);

        return response()->json($this->voucherOut($this->voucherOrFail($user->company_id, $entryId)));
    }

    public function exportVouchersCsv(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);

        return response(Exports::rowsToCsv(self::VOUCHER_EXPORT_FIELDS, $this->voucherRows($user->company_id, $request)), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=journal-vouchers.csv',
        ]);
    }

    public function exportVouchersExcel(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);
        $data = Exports::rowsToExcel(self::VOUCHER_EXPORT_FIELDS, $this->voucherRows($user->company_id, $request), 'Journal Vouchers');

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=journal-vouchers.xlsx',
        ]);
    }

    /**
     * Raise a Journal Voucher. A draft unless `post` is set -- and
     * either way it has to balance before it can be posted.
     */
    public function createVoucher(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'entry_date' => 'required|date',
            'narration' => 'required|string|min:1',
            'lines' => 'required|array|min:1',
            'lines.*.account_id' => 'required|uuid',
            'lines.*.debit_sgd' => 'sometimes|numeric|min:0',
            'lines.*.credit_sgd' => 'sometimes|numeric|min:0',
            'lines.*.description' => 'sometimes|nullable|string',
            'post' => 'sometimes|boolean',
        ]);

        // Every line must name an account of this company -- Python
        // relies on the FK alone, so this is hardening, consistent with
        // the other conversions.
        foreach ($data['lines'] as $line) {
            $this->accountOrFail($user->company_id, $line['account_id']);
        }

        $post = $data['post'] ?? false;

        try {
            $entry = DB::transaction(function () use ($data, $user, $post) {
                $entry = Ledger::createJournalEntry(
                    $user->company_id,
                    Carbon::parse($data['entry_date']),
                    $data['narration'],
                    $data['lines'],
                    JournalEntry::TYPE_JOURNAL,
                    $user->id,
                );
                if ($post) {
                    Ledger::postEntry($entry, $user->id);
                }

                Audit::record(
                    entityType: 'journal_entry',
                    entityId: $entry->id,
                    action: $post ? 'posted' : 'created',
                    actorUserId: $user->id,
                    details: "{$entry->voucher_number}: {$data['narration']}",
                    newValue: [
                        'voucher_number' => $entry->voucher_number,
                        'debit_sgd' => $entry->totalDebit()->toString(),
                        'credit_sgd' => $entry->totalCredit()->toString(),
                        'status' => $entry->status,
                    ],
                );

                return $entry;
            });
        } catch (LedgerRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->voucherOut($entry->fresh('lines')));
    }

    /** Post a draft to the ledger. Refuses anything that does not balance. */
    public function postVoucher(Request $request, string $entryId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);
        $entry = $this->voucherOrFail($user->company_id, $entryId);

        try {
            DB::transaction(function () use ($entry, $user) {
                Ledger::postEntry($entry, $user->id);

                Audit::record(
                    entityType: 'journal_entry',
                    entityId: $entry->id,
                    action: 'posted',
                    actorUserId: $user->id,
                    details: "{$entry->voucher_number}: SGD ".$entry->totalDebit()->toString(),
                    oldValue: ['status' => JournalEntry::STATUS_DRAFT],
                    newValue: ['status' => JournalEntry::STATUS_POSTED],
                );
            });
        } catch (LedgerRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->voucherOut($entry->fresh('lines')));
    }

    /**
     * Reverse a posted voucher. The original is left exactly as it was
     * and a mirror entry written instead, so both the mistake and its
     * correction stay on record. Returns the REVERSAL, as Python does.
     */
    public function reverseVoucher(Request $request, string $entryId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);
        $data = $request->validate(['reason' => 'required|string|min:1']);
        $entry = $this->voucherOrFail($user->company_id, $entryId);

        try {
            $reversal = DB::transaction(function () use ($entry, $user, $data) {
                $reversal = Ledger::reverseEntry($entry, $user->id, $data['reason']);

                Audit::record(
                    entityType: 'journal_entry',
                    entityId: $entry->id,
                    action: 'reversed',
                    actorUserId: $user->id,
                    reason: $data['reason'],
                    details: "{$entry->voucher_number} reversed by {$reversal->voucher_number}",
                    oldValue: ['status' => JournalEntry::STATUS_POSTED],
                    newValue: ['status' => JournalEntry::STATUS_REVERSED, 'reversal_voucher' => $reversal->voucher_number],
                );

                return $reversal;
            });
        } catch (LedgerRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->voucherOut($reversal->fresh('lines')));
    }

    private function at(Request $request, string $level): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, $level);

        return $user;
    }

    private function voucherOrFail(string $companyId, string $entryId): JournalEntry
    {
        $entry = JournalEntry::with('lines')->where('company_id', $companyId)->find($entryId);
        if (! $entry) {
            throw new ApiException(404, 'Voucher not found');
        }

        return $entry;
    }

    /** @return Collection<int, JournalEntry> */
    private function filteredVouchers(string $companyId, Request $request)
    {
        $query = JournalEntry::with('lines')->where('company_id', $companyId);
        if ($type = $request->query('voucher_type')) {
            $query->where('voucher_type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('entry_date')->orderByDesc('created_at')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function voucherRows(string $companyId, Request $request): array
    {
        return $this->filteredVouchers($companyId, $request)->map(fn (JournalEntry $e) => [
            'voucher_number' => $e->voucher_number,
            'voucher_type' => $e->voucher_type,
            'entry_date' => $e->entry_date?->toDateString(),
            'narration' => $e->narration,
            'status' => $e->status,
            // Python formats both to 2dp strings in the export.
            'total_debit_sgd' => number_format($e->totalDebit()->toFloat(), 2, '.', ''),
            'total_credit_sgd' => number_format($e->totalCredit()->toFloat(), 2, '.', ''),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function voucherOut(JournalEntry $e): array
    {
        return [
            'id' => $e->id,
            'voucher_number' => $e->voucher_number,
            'voucher_type' => $e->voucher_type,
            'entry_date' => $e->entry_date?->toDateString(),
            'narration' => $e->narration,
            'status' => $e->status,
            'total_debit' => $e->totalDebit()->toFloat(),
            'total_credit' => $e->totalCredit()->toFloat(),
            // Mirrors Python's is_balanced exactly: equal AND non-zero.
            // An all-zero voucher is NOT balanced -- it is empty.
            'is_balanced' => $e->totalDebit()->toFloat() === $e->totalCredit()->toFloat()
                && $e->totalDebit()->toFloat() > 0,
            'reverses_entry_id' => $e->reverses_entry_id,
            'lines' => $e->lines->map(fn (JournalLine $l) => [
                'id' => $l->id,
                'account_id' => $l->account_id,
                'account_code' => $l->account?->code,
                'account_name' => $l->account?->name,
                'debit_sgd' => (float) $l->debit_sgd,
                'credit_sgd' => (float) $l->credit_sgd,
                'description' => $l->description,
            ])->values(),
        ];
    }

    private function accountOrFail(string $companyId, string $accountId): Account
    {
        $account = Account::find($accountId);
        if (! $account || $account->company_id !== $companyId) {
            throw new ApiException(404, 'Account not found');
        }

        return $account;
    }

    private function trialBalanceRows(string $companyId, ?Carbon $asAt): array
    {
        return array_map(fn (array $r) => [
            'account_id' => $r['account_id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'account_type' => $r['account_type'],
            'debit_sgd' => $r['debit_sgd']->toFloat(),
            'credit_sgd' => $r['credit_sgd']->toFloat(),
            'balance_sgd' => $r['balance_sgd']->toFloat(),
        ], Ledger::accountBalances($companyId, $asAt));
    }

    /** Posted debits and credits per account. Draft and reversed vouchers are excluded. */
    /** @var array<int, string> */
    private const GL_TRANSACTION_EXPORT_FIELDS = [
        'voucher_number', 'voucher_type', 'entry_date', 'narration',
        'line_description', 'debit_sgd', 'credit_sgd', 'balance_sgd',
    ];

    public function exportTrialBalanceCsv(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);
        $rows = $this->trialBalanceExportRows($user->company_id, $request);

        return response(Exports::rowsToCsv(self::TRIAL_BALANCE_EXPORT_FIELDS, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=trial-balance.csv',
        ]);
    }

    public function exportTrialBalanceExcel(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);
        $rows = $this->trialBalanceExportRows($user->company_id, $request);
        $data = Exports::rowsToExcel(self::TRIAL_BALANCE_EXPORT_FIELDS, $rows, 'Trial Balance');

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=trial-balance.xlsx',
        ]);
    }

    public function exportTransactionsCsv(Request $request, string $accountId)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);
        $rows = $this->transactionExportRows($user->company_id, $accountId, $request);

        return response(Exports::rowsToCsv(self::GL_TRANSACTION_EXPORT_FIELDS, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=gl-transactions.csv',
        ]);
    }

    public function exportTransactionsExcel(Request $request, string $accountId)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);
        $rows = $this->transactionExportRows($user->company_id, $accountId, $request);
        $data = Exports::rowsToExcel(self::GL_TRANSACTION_EXPORT_FIELDS, $rows, 'GL Transactions');

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=gl-transactions.xlsx',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function trialBalanceExportRows(string $companyId, Request $request): array
    {
        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;

        return array_map(fn (array $r) => [
            'code' => $r['code'],
            'name' => $r['name'],
            'account_type' => $r['account_type'],
            'debit_sgd' => $r['debit_sgd'],
            'credit_sgd' => $r['credit_sgd'],
            'balance_sgd' => $r['balance_sgd'],
        ], $this->trialBalanceRows($companyId, $asAt));
    }

    /** @return array<int, array<string, mixed>> */
    private function transactionExportRows(string $companyId, string $accountId, Request $request): array
    {
        $this->accountOrFail($companyId, $accountId);
        $from = $request->filled('date_from') ? Carbon::parse($request->query('date_from')) : null;
        $to = $request->filled('date_to') ? Carbon::parse($request->query('date_to')) : null;

        return array_map(fn (array $r) => [
            'voucher_number' => $r['voucher_number'] ?? '',
            'voucher_type' => $r['voucher_type'] ?? '',
            'entry_date' => $r['entry_date'] ?? '',
            'narration' => $r['narration'] ?? '',
            'line_description' => $r['line_description'] ?? '',
            'debit_sgd' => $r['debit_sgd'] ?? 0,
            'credit_sgd' => $r['credit_sgd'] ?? 0,
            'balance_sgd' => $r['balance_sgd'] ?? 0,
        ], Ledger::accountTransactions($companyId, $accountId, $from, $to));
    }

    public function trialBalance(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $asAt = $request->filled('as_at') ? Carbon::parse($request->query('as_at')) : null;
        $rows = $this->trialBalanceRows($user->company_id, $asAt);
        $totalDebit = round(array_sum(array_column($rows, 'debit_sgd')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit_sgd')), 2);

        return response()->json([
            'as_at' => $asAt?->toDateString(),
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            // A trial balance that doesn't balance means something is
            // wrong with the ledger itself, so it is surfaced rather
            // than hidden.
            'is_balanced' => $totalDebit === $totalCredit,
        ]);
    }

    /**
     * GL transaction ledger for one account -- every posted
     * debit/credit with running balance. Use the account_id from the
     * trial balance or chart of accounts.
     */
    public function accountTransactions(Request $request, string $accountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $account = $this->accountOrFail($user->company_id, $accountId);
        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->query('date_from')) : null;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->query('date_to')) : null;

        $rows = Ledger::accountTransactions($user->company_id, $account->id, $dateFrom, $dateTo);
        $totalDebit = array_sum(array_column($rows, 'debit_sgd'));
        $totalCredit = array_sum(array_column($rows, 'credit_sgd'));
        $closingBalance = $rows === [] ? 0.0 : $rows[count($rows) - 1]['balance_sgd'];

        return response()->json([
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'account_type' => $account->account_type,
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $closingBalance,
        ]);
    }
}
