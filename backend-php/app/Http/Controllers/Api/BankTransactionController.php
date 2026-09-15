<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\GroupModuleAuthority;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\BankBook;
use App\Services\Numbering;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Bank Book: bank transactions and Bank Reconciliation. Mirrors
 * backend/app/routers/bank_transactions.py 1:1.
 *
 * DELIBERATELY ITS OWN LEDGER, separate from the General Ledger's
 * Journal Vouchers -- confirmed with Dennis, because nothing auto-posts
 * to the GL except manually entered Journal Vouchers, so tying a
 * day-to-day Bank Book to it would mean keying every bank line as a JV
 * first. A BankAccount's gl_account_id stays setup metadata only.
 *
 * Gated on `finance_accounting`.
 *
 * A wrong entry is VOIDED WITH A REASON, never deleted: it stays
 * visible in the ledger, struck through, and stops moving the balance.
 * CLAUDE.md forbids deleting financial records.
 */
class BankTransactionController extends Controller
{
    private const MODULE = 'finance_accounting';

    public function index(Request $request, string $bankAccountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);
        $bankAccount = $this->bankAccountOrFail($user->company_id, $bankAccountId);

        $txns = BankTransaction::where('bank_account_id', $bankAccount->id)
            ->orderBy('transaction_date')->orderBy('created_at')->get();

        $running = Money::of($bankAccount->opening_balance_sgd);
        $unreconciled = 0;
        $rows = [];
        foreach ($txns as $txn) {
            // A voided line stays visible but never moves the running
            // balance -- which is why the balance is accumulated here
            // rather than read per row.
            if (! $txn->is_voided) {
                $running = $running->plus(Money::of($txn->debit_sgd))->minus(Money::of($txn->credit_sgd));
                if (! $txn->is_reconciled) {
                    $unreconciled++;
                }
            }
            $rows[] = $this->out($txn, $running);
        }

        return response()->json([
            'bank_account_id' => $bankAccount->id,
            'opening_balance_sgd' => (float) $bankAccount->opening_balance_sgd,
            'opening_balance_date' => $bankAccount->opening_balance_date?->toDateString(),
            'rows' => $rows,
            'closing_balance_sgd' => $running->toFloat(),
            'reconciled_balance_sgd' => BankBook::reconciledBalance(
                $bankAccount->id, $bankAccount->opening_balance_sgd
            )->toFloat(),
            'unreconciled_count' => $unreconciled,
        ]);
    }

    public function store(Request $request, string $bankAccountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);
        $bankAccount = $this->bankAccountOrFail($user->company_id, $bankAccountId);

        $data = $request->validate([
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:500',
            'reference' => 'sometimes|nullable|string|max:200',
            'debit_sgd' => 'sometimes|numeric|min:0',
            'credit_sgd' => 'sometimes|numeric|min:0',
        ]);

        $debit = Money::of($data['debit_sgd'] ?? 0);
        $credit = Money::of($data['credit_sgd'] ?? 0);
        // A line carries one or the other, never both -- the same
        // convention JournalLine uses, for the same reason.
        if ($debit->toFloat() > 0 && $credit->toFloat() > 0) {
            throw new ApiException(422, 'A transaction is either a debit or a credit, not both.');
        }
        if ($debit->toFloat() == 0 && $credit->toFloat() == 0) {
            throw new ApiException(422, 'Enter a debit or a credit amount.');
        }

        $txn = DB::transaction(function () use ($data, $user, $bankAccount, $debit, $credit) {
            $txn = BankTransaction::create([
                'company_id' => $user->company_id,
                'bank_account_id' => $bankAccount->id,
                'transaction_number' => Numbering::next($user->company_id, 'bank_transaction'),
                'transaction_date' => $data['transaction_date'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'debit_sgd' => $debit->quantize()->toString(),
                'credit_sgd' => $credit->quantize()->toString(),
                'created_by_user_id' => $user->id,
            ]);

            Audit::record(
                entityType: 'bank_transaction',
                entityId: $txn->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$bankAccount->bank_name} {$bankAccount->account_number}: {$data['description']}",
                newValue: [
                    'transaction_number' => $txn->transaction_number,
                    'debit_sgd' => $debit->toFloat() > 0 ? $debit->quantize()->toString() : null,
                    'credit_sgd' => $credit->toFloat() > 0 ? $credit->quantize()->toString() : null,
                ],
            );

            return $txn;
        });

        return response()->json($this->out($txn->refresh(), BankBook::currentBalance($bankAccount)));
    }

    public function void(Request $request, string $transactionId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate(['reason' => 'required|string|max:500']);
        $txn = $this->transactionOrFail($user->company_id, $transactionId);
        if ($txn->is_voided) {
            throw new ApiException(409, 'That transaction is already voided.');
        }

        DB::transaction(function () use ($txn, $user, $data) {
            $txn->is_voided = true;
            $txn->void_reason = $data['reason'];
            $txn->voided_at = Carbon::now('UTC');
            $txn->save();

            Audit::record(
                entityType: 'bank_transaction',
                entityId: $txn->id,
                action: 'voided',
                actorUserId: $user->id,
                oldValue: ['is_voided' => false],
                newValue: ['is_voided' => true, 'void_reason' => $data['reason']],
            );
        });

        $bankAccount = BankAccount::find($txn->bank_account_id);

        return response()->json($this->out($txn->refresh(), BankBook::currentBalance($bankAccount)));
    }

    /**
     * A quick per-line correction -- ticking or unticking one line
     * without redoing a whole reconciliation session. The formal
     * session below is still how a reconciliation gets recorded.
     */
    public function toggleReconciled(Request $request, string $transactionId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $txn = $this->transactionOrFail($user->company_id, $transactionId);

        DB::transaction(function () use ($txn, $user) {
            $was = $txn->is_reconciled;
            $txn->is_reconciled = ! $was;
            $txn->reconciled_at = $txn->is_reconciled ? Carbon::now('UTC') : null;
            $txn->save();

            Audit::record(
                entityType: 'bank_transaction',
                entityId: $txn->id,
                action: $txn->is_reconciled ? 'reconciled' : 'unreconciled',
                actorUserId: $user->id,
                oldValue: ['is_reconciled' => $was],
                newValue: ['is_reconciled' => $txn->is_reconciled],
            );
        });

        $bankAccount = BankAccount::find($txn->bank_account_id);

        return response()->json($this->out($txn->refresh(), BankBook::currentBalance($bankAccount)));
    }

    public function listReconciliations(Request $request, string $bankAccountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);
        $bankAccount = $this->bankAccountOrFail($user->company_id, $bankAccountId);

        $names = User::where('company_id', $user->company_id)->pluck('full_name', 'id');

        return response()->json(
            BankReconciliation::where('bank_account_id', $bankAccount->id)
                ->orderByDesc('statement_date')->orderByDesc('created_at')->get()
                ->map(fn (BankReconciliation $r) => $this->reconciliationOut($r, $names[$r->reconciled_by_user_id] ?? null))
        );
    }

    /**
     * Save a reconciliation session: tick off the listed transactions
     * (anything already reconciled is left alone) and snapshot the
     * ledger balance as at the statement date against the statement's
     * own, so the difference is on permanent record.
     */
    public function storeReconciliation(Request $request, string $bankAccountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);
        $bankAccount = $this->bankAccountOrFail($user->company_id, $bankAccountId);

        $data = $request->validate([
            'statement_date' => 'required|date',
            'statement_balance_sgd' => 'required|numeric',
            'note' => 'sometimes|nullable|string',
            'reconciled_transaction_ids' => 'sometimes|array',
            'reconciled_transaction_ids.*' => 'uuid',
        ]);

        $reconciliation = DB::transaction(function () use ($data, $user, $bankAccount) {
            foreach ($data['reconciled_transaction_ids'] ?? [] as $txnId) {
                $txn = BankTransaction::find($txnId);
                if (! $txn || $txn->bank_account_id !== $bankAccount->id) {
                    throw new ApiException(400, 'One of the transactions to reconcile was not found.');
                }
                if (! $txn->is_reconciled) {
                    $txn->is_reconciled = true;
                    $txn->reconciled_at = Carbon::now('UTC');
                    $txn->save();
                }
            }

            $ledger = BankBook::currentBalance($bankAccount, Carbon::parse($data['statement_date']));
            $statement = Money::of($data['statement_balance_sgd']);
            $difference = $statement->minus($ledger);

            $reconciliation = BankReconciliation::create([
                'company_id' => $user->company_id,
                'bank_account_id' => $bankAccount->id,
                'statement_date' => $data['statement_date'],
                'statement_balance_sgd' => $statement->quantize()->toString(),
                'ledger_balance_sgd' => $ledger->quantize()->toString(),
                'difference_sgd' => $difference->quantize()->toString(),
                'note' => $data['note'] ?? null,
                'reconciled_by_user_id' => $user->id,
            ]);

            Audit::record(
                entityType: 'bank_reconciliation',
                entityId: $reconciliation->id,
                action: 'reconciled',
                actorUserId: $user->id,
                details: "{$bankAccount->bank_name} {$bankAccount->account_number} as at {$data['statement_date']}",
                newValue: [
                    'statement_balance_sgd' => $statement->quantize()->toString(),
                    'ledger_balance_sgd' => $ledger->quantize()->toString(),
                    'difference_sgd' => $difference->quantize()->toString(),
                ],
            );

            return $reconciliation;
        });

        return response()->json($this->reconciliationOut($reconciliation->refresh(), $user->full_name));
    }

    private function bankAccountOrFail(string $companyId, string $bankAccountId): BankAccount
    {
        $bank = BankAccount::where('company_id', $companyId)->find($bankAccountId);
        if (! $bank) {
            throw new ApiException(404, 'Bank account not found');
        }

        return $bank;
    }

    private function transactionOrFail(string $companyId, string $transactionId): BankTransaction
    {
        $txn = BankTransaction::where('company_id', $companyId)->find($transactionId);
        if (! $txn) {
            throw new ApiException(404, 'Bank transaction not found');
        }

        return $txn;
    }

    /** @return array<string, mixed> */
    private function out(BankTransaction $txn, Money $runningBalance): array
    {
        return [
            'id' => $txn->id,
            'bank_account_id' => $txn->bank_account_id,
            'transaction_number' => $txn->transaction_number,
            'transaction_date' => $txn->transaction_date?->toDateString(),
            'description' => $txn->description,
            'reference' => $txn->reference,
            'debit_sgd' => (float) $txn->debit_sgd,
            'credit_sgd' => (float) $txn->credit_sgd,
            'is_reconciled' => $txn->is_reconciled,
            'reconciled_at' => $txn->reconciled_at?->toJSON(),
            'is_voided' => $txn->is_voided,
            'void_reason' => $txn->void_reason,
            'voided_at' => $txn->voided_at?->toJSON(),
            'created_at' => $txn->created_at?->toJSON(),
            'running_balance_sgd' => $runningBalance->toFloat(),
            'source_type' => $txn->source_type,
            'source_id' => $txn->source_id,
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliationOut(BankReconciliation $r, ?string $reconciledByName): array
    {
        return [
            'id' => $r->id,
            'bank_account_id' => $r->bank_account_id,
            'statement_date' => $r->statement_date?->toDateString(),
            'statement_balance_sgd' => (float) $r->statement_balance_sgd,
            'ledger_balance_sgd' => (float) $r->ledger_balance_sgd,
            'difference_sgd' => (float) $r->difference_sgd,
            'note' => $r->note,
            'reconciled_by_user_id' => $r->reconciled_by_user_id,
            'reconciled_by_name' => $reconciledByName,
            'created_at' => $r->created_at?->toJSON(),
        ];
    }
}
