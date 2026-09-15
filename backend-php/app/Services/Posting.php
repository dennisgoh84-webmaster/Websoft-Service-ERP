<?php

namespace App\Services;

use App\Exceptions\LedgerRuleViolation;
use App\Exceptions\PeriodClosedError;
use App\Exceptions\PostingError;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sub-ledger -> General Ledger posting (ACC-001..004). Mirrors
 * backend/app/services/posting.py -- see docs/gl-posting-design.md
 * for the rules and the decisions behind them, and that Python
 * file's own docstring for the design points that matter when
 * changing this class (the ACCOUNT MAP is the only place account
 * codes live; every posting links back to its document via
 * source_type/source_id; auto-posted vouchers reuse their source
 * document's own number; GL posting happens automatically at the
 * accounting event, ACC-003).
 *
 * NOT yet converted: gl_status_map/bank_status_map/decorate() as a
 * *batch* one-query-per-list helper (Python's own optimisation for
 * list screens) -- callers here fetch per-document instead, correct
 * but not yet optimised for a large list.
 */
class Posting
{
    // Account map (docs/gl-posting-design.md §3).
    public const AR_CONTROL = '1100';

    public const AP_CONTROL = '2000';

    public const GST_OUTPUT = '2100';

    public const GST_INPUT = '2110';

    public const CASH_AT_BANK_FALLBACK = '1000';

    public const EXPENSE_DEFAULT = '5000';

    private const REVENUE_BY_INVOICE_TYPE = [
        Invoice::TYPE_CONTRACT_ANNUAL => '4000',
        Invoice::TYPE_EXCESS_USAGE => '4010',
        // A manually raised Sales Invoice sells stock items, so it
        // lands in the seeded "Hardware sales" account rather than a
        // new one invented here. Remap it in the Chart of Accounts if
        // Webmaster wants these somewhere else.
        Invoice::TYPE_SALES => '4030',
    ];

    // source_type values -- shared with the Bank step and UNGL endpoints.
    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_SUPPLIER_INVOICE = 'supplier_invoice';

    public const SOURCE_RECEIPT = 'payment'; // the receipt model is `Payment` (not yet converted)

    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';

    public static function accountByCode(string $companyId, string $code): Account
    {
        $account = Account::where('company_id', $companyId)->where('code', $code)->first();
        if ($account === null) {
            throw new PostingError("GL account {$code} is missing from this company's Chart of Accounts, so this document cannot be posted. Add it under Chart of Accounts.");
        }
        if (! $account->is_active) {
            throw new PostingError("GL account {$code} {$account->name} is retired and cannot be posted to.");
        }

        return $account;
    }

    /** The GL account behind a bank account: its own gl_account_id, else 1000 Cash at bank. */
    public static function bankGlAccount(string $companyId, ?string $bankAccountId): Account
    {
        if ($bankAccountId === null) {
            throw new PostingError('A bank account is required before this voucher can be posted.');
        }
        $bank = BankAccount::find($bankAccountId);
        if ($bank === null || $bank->company_id !== $companyId) {
            throw new PostingError('Unknown bank account on this voucher.');
        }
        if ($bank->gl_account_id !== null) {
            $account = Account::find($bank->gl_account_id);
            if ($account !== null && $account->is_active) {
                return $account;
            }
        }

        return self::accountByCode($companyId, self::CASH_AT_BANK_FALLBACK);
    }

    /** The one non-reversed entry posted for a document, if any. */
    public static function liveEntryFor(string $sourceType, string $sourceId): ?JournalEntry
    {
        return JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', '!=', JournalEntry::STATUS_REVERSED)
            ->first();
    }

    /** @param  array<int, array{account_id: string, account_code: string, debit_sgd?: string|int|float, credit_sgd?: string|int|float, description?: ?string}>  $lines */
    private static function post(
        string $companyId,
        string $voucherType,
        string $voucherNumber,
        Carbon $entryDate,
        string $narration,
        array $lines,
        string $sourceType,
        string $sourceId,
        ?string $actorUserId,
        string $auditEntityType,
    ): JournalEntry {
        if (self::liveEntryFor($sourceType, $sourceId) !== null) {
            throw new PostingError("{$voucherNumber} is already posted to the GL.");
        }

        try {
            $entry = Ledger::createJournalEntry(
                companyId: $companyId, entryDate: $entryDate, narration: $narration, lines: $lines,
                voucherType: $voucherType, createdByUserId: $actorUserId,
                sourceType: $sourceType, sourceId: $sourceId, voucherNumber: $voucherNumber,
            );
            Ledger::postEntry($entry, $actorUserId);
        } catch (LedgerRuleViolation $e) {
            throw new PostingError($e->getMessage());
        }

        Audit::record(
            entityType: $auditEntityType,
            entityId: $sourceId,
            action: 'gl_posted',
            actorUserId: $actorUserId,
            details: "{$voucherNumber} posted to GL",
            newValue: [
                'journal_entry_id' => $entry->id,
                'voucher_number' => $entry->voucher_number,
                'entry_date' => $entryDate->toDateString(),
                'lines' => array_map(fn ($ln) => [
                    'account' => $ln['account_code'],
                    'debit' => (string) ($ln['debit_sgd'] ?? 0),
                    'credit' => (string) ($ln['credit_sgd'] ?? 0),
                ], $lines),
            ],
        );

        return $entry;
    }

    private static function line(Account $account, Money $debit, Money $credit, ?string $description = null): array
    {
        return [
            'account_id' => $account->id,
            'account_code' => $account->code, // for the audit trail only; ignored by the ledger
            'debit_sgd' => $debit->toString(),
            'credit_sgd' => $credit->toString(),
            'description' => $description,
        ];
    }

    /** §4.1 Sales Invoice -- on issue. Dr 1100 AR total / Cr revenue net / Cr 2100 GST output. */
    public static function postInvoice(Invoice $invoice, ?string $actorUserId): JournalEntry
    {
        $cid = $invoice->company_id;
        $revenueCode = self::REVENUE_BY_INVOICE_TYPE[$invoice->invoice_type] ?? null;
        if ($revenueCode === null) {
            throw new PostingError("No revenue account is mapped for invoice type '{$invoice->invoice_type}'.");
        }
        $ar = self::accountByCode($cid, self::AR_CONTROL);
        $revenue = self::accountByCode($cid, $revenueCode);
        $total = Money::of($invoice->total_amount_sgd);
        $net = Money::of($invoice->amount_sgd);
        $gst = Money::of($invoice->gst_amount_sgd ?? 0);

        $customerName = $invoice->customer?->name ?? '';
        $lines = [
            self::line($ar, $total, Money::of(0), trim("{$invoice->invoice_number} {$customerName}")),
            self::line($revenue, Money::of(0), $net, $invoice->description),
        ];
        if ($gst->toFloat() > 0) {
            $lines[] = self::line(self::accountByCode($cid, self::GST_OUTPUT), Money::of(0), $gst, "GST {$invoice->tax_code}");
        }

        $entryDate = $invoice->issued_at ? Carbon::parse($invoice->issued_at) : Carbon::today();

        return self::post(
            companyId: $cid, voucherType: JournalEntry::TYPE_SALES_INVOICE, voucherNumber: $invoice->invoice_number,
            entryDate: $entryDate, narration: trim("Sales invoice {$invoice->invoice_number} — {$customerName}", ' —'),
            lines: $lines, sourceType: self::SOURCE_INVOICE, sourceId: $invoice->id,
            actorUserId: $actorUserId, auditEntityType: 'invoice',
        );
    }

    /** §4.2 Supplier Bill -- on reaching approved. Dr expense net / Dr 2110 GST input / Cr 2000 AP total. */
    public static function postSupplierInvoice(SupplierInvoice $bill, ?string $actorUserId): JournalEntry
    {
        $cid = $bill->company_id;
        if ($bill->expense_account_id !== null) {
            $expense = Account::find($bill->expense_account_id);
            if ($expense === null || $expense->company_id !== $cid || ! $expense->is_active) {
                throw new PostingError('The expense account on this bill is missing or retired.');
            }
        } else {
            $expense = self::accountByCode($cid, self::EXPENSE_DEFAULT);
        }
        $ap = self::accountByCode($cid, self::AP_CONTROL);
        $net = Money::of($bill->amount_sgd);
        $gst = Money::of($bill->gst_amount_sgd ?? 0);
        $total = Money::of($bill->total_amount_sgd);

        $supplierName = $bill->supplier?->name ?? '';
        $lines = [self::line($expense, $net, Money::of(0), $bill->description)];
        if ($gst->toFloat() > 0) {
            $lines[] = self::line(self::accountByCode($cid, self::GST_INPUT), $gst, Money::of(0), 'GST input');
        }
        $lines[] = self::line($ap, Money::of(0), $total, trim("{$bill->bill_number} {$supplierName}"));

        return self::post(
            companyId: $cid, voucherType: JournalEntry::TYPE_PURCHASE_INVOICE, voucherNumber: $bill->bill_number,
            entryDate: $bill->invoice_date, narration: trim("Supplier bill {$bill->bill_number} — {$supplierName}", ' —'),
            lines: $lines, sourceType: self::SOURCE_SUPPLIER_INVOICE, sourceId: $bill->id,
            actorUserId: $actorUserId, auditEntityType: 'supplier_invoice',
        );
    }

    /** §4.3 Receipt Voucher -- on save. Dr bank GL / Cr 1100 AR. */
    public static function postReceipt(Payment $payment, ?string $actorUserId): JournalEntry
    {
        $cid = $payment->company_id;
        $bank = self::bankGlAccount($cid, $payment->bank_account_id);
        $ar = self::accountByCode($cid, self::AR_CONTROL);
        $amount = Money::of($payment->amount_sgd);
        $customerName = $payment->customer?->name ?? '';
        $ref = $payment->reference ? " ref {$payment->reference}" : '';

        return self::post(
            companyId: $cid, voucherType: JournalEntry::TYPE_RECEIPT, voucherNumber: $payment->voucher_number,
            entryDate: $payment->payment_date, narration: trim("Receipt {$payment->voucher_number} — {$customerName}{$ref}", ' —'),
            lines: [
                self::line($bank, $amount, Money::of(0), "{$payment->voucher_number}{$ref}"),
                self::line($ar, Money::of(0), $amount, $customerName ?: null),
            ],
            sourceType: self::SOURCE_RECEIPT, sourceId: $payment->id,
            actorUserId: $actorUserId, auditEntityType: 'payment',
        );
    }

    /** §4.4 Payment Voucher -- on save. Dr 2000 AP / Cr bank GL. */
    public static function postSupplierPayment(SupplierPayment $payment, ?string $actorUserId): JournalEntry
    {
        $cid = $payment->company_id;
        $bank = self::bankGlAccount($cid, $payment->bank_account_id);
        $ap = self::accountByCode($cid, self::AP_CONTROL);
        $amount = Money::of($payment->amount_sgd);
        $supplierName = $payment->supplier?->name ?? '';
        $ref = $payment->reference ? " ref {$payment->reference}" : '';

        return self::post(
            companyId: $cid, voucherType: JournalEntry::TYPE_PAYMENT, voucherNumber: $payment->voucher_number,
            entryDate: $payment->payment_date, narration: trim("Payment {$payment->voucher_number} — {$supplierName}{$ref}", ' —'),
            lines: [
                self::line($ap, $amount, Money::of(0), $supplierName ?: null),
                self::line($bank, Money::of(0), $amount, "{$payment->voucher_number}{$ref}"),
            ],
            sourceType: self::SOURCE_SUPPLIER_PAYMENT, sourceId: $payment->id,
            actorUserId: $actorUserId, auditEntityType: 'supplier_payment',
        );
    }

    // §4.6 UNGL (ACC-004).
    private static function guard(string $companyId, Carbon $on, string $docType, string $operation): void
    {
        try {
            Periods::requireAllows($companyId, $on, $docType, $operation);
        } catch (PeriodClosedError $e) {
            throw new PostingError($e->getMessage());
        }
    }

    /**
     * Reverse a document's live GL entry. Nothing is deleted: the
     * original is marked reversed and a mirror-image voucher is
     * posted (ACC-004).
     */
    public static function unpost(string $sourceType, string $sourceId, string $actorUserId, string $reason, string $auditEntityType): JournalEntry
    {
        $entry = self::liveEntryFor($sourceType, $sourceId);
        if ($entry === null) {
            throw new PostingError('This document has no live GL posting to reverse.');
        }
        self::guard($entry->company_id, $entry->entry_date, Periods::voucherTypeToDocType($entry->voucher_type), 'ungl');

        // Label the reversal from its source ("RV-2026-0003-REV", "-REV2"
        // if the document was reversed and re-posted before) instead of
        // drawing a number from the document's own counter.
        $prior = JournalEntry::where('company_id', $entry->company_id)
            ->where('voucher_number', 'like', "{$entry->voucher_number}-REV%")
            ->count();
        $label = "{$entry->voucher_number}-REV".($prior > 0 ? (string) ($prior + 1) : '');

        try {
            $reversal = Ledger::reverseEntry($entry, $actorUserId, $reason, $label);
        } catch (LedgerRuleViolation $e) {
            throw new PostingError($e->getMessage());
        }

        Audit::record(
            entityType: $auditEntityType,
            entityId: $sourceId,
            action: 'gl_unposted',
            actorUserId: $actorUserId,
            reason: $reason,
            details: "{$entry->voucher_number} reversed by {$reversal->voucher_number}",
            newValue: ['reversed_entry_id' => $entry->id, 'reversal_entry_id' => $reversal->id],
        );

        return $reversal;
    }

    // §4.5 Bank step (ACC-002) -- receipts and payments only.
    public static function liveBankTransactionFor(string $sourceType, string $sourceId): ?BankTransaction
    {
        return BankTransaction::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('is_voided', false)
            ->first();
    }

    private static function bank(
        string $companyId,
        ?string $bankAccountId,
        string $sourceType,
        string $sourceId,
        string $voucherNumber,
        Carbon $on,
        Money $amount,
        bool $moneyIn,
        string $description,
        ?string $reference,
        string $docType,
        string $actorUserId,
        string $auditEntityType,
    ): BankTransaction {
        if ($bankAccountId === null) {
            throw new PostingError('Set a bank account on this voucher before banking it.');
        }
        $bank = BankAccount::find($bankAccountId);
        if ($bank === null || $bank->company_id !== $companyId) {
            throw new PostingError('Unknown bank account on this voucher.');
        }
        if (self::liveBankTransactionFor($sourceType, $sourceId) !== null) {
            throw new PostingError("{$voucherNumber} is already in the bank book.");
        }
        self::guard($companyId, $on, $docType, 'bank');

        $txn = BankTransaction::create([
            'company_id' => $companyId,
            'bank_account_id' => $bank->id,
            'transaction_number' => Numbering::next($companyId, 'bank_transaction'),
            'transaction_date' => $on->toDateString(),
            'description' => $description,
            'reference' => $reference,
            'debit_sgd' => $moneyIn ? $amount->toString() : '0.00',
            'credit_sgd' => $moneyIn ? '0.00' : $amount->toString(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by_user_id' => $actorUserId,
        ]);

        Audit::record(
            entityType: $auditEntityType,
            entityId: $sourceId,
            action: 'banked',
            actorUserId: $actorUserId,
            details: "{$voucherNumber} entered in bank book as {$txn->transaction_number} ({$bank->bank_name})",
            newValue: [
                'bank_transaction_id' => $txn->id, 'transaction_number' => $txn->transaction_number,
                'amount_sgd' => $amount->toString(), 'direction' => $moneyIn ? 'in' : 'out',
            ],
        );

        return $txn;
    }

    public static function bankReceipt(Payment $payment, string $actorUserId): BankTransaction
    {
        $customerName = $payment->customer?->name ?? '';

        return self::bank(
            companyId: $payment->company_id, bankAccountId: $payment->bank_account_id,
            sourceType: self::SOURCE_RECEIPT, sourceId: $payment->id,
            voucherNumber: $payment->voucher_number, on: $payment->payment_date,
            amount: Money::of($payment->amount_sgd), moneyIn: true,
            description: trim("{$payment->voucher_number} — {$customerName}", ' —'),
            reference: $payment->reference, docType: 'receipt_voucher',
            actorUserId: $actorUserId, auditEntityType: 'payment',
        );
    }

    public static function bankSupplierPayment(SupplierPayment $payment, string $actorUserId): BankTransaction
    {
        $supplierName = $payment->supplier?->name ?? '';

        return self::bank(
            companyId: $payment->company_id, bankAccountId: $payment->bank_account_id,
            sourceType: self::SOURCE_SUPPLIER_PAYMENT, sourceId: $payment->id,
            voucherNumber: $payment->voucher_number, on: $payment->payment_date,
            amount: Money::of($payment->amount_sgd), moneyIn: false,
            description: trim("{$payment->voucher_number} — {$supplierName}", ' —'),
            reference: $payment->reference, docType: 'payment_voucher',
            actorUserId: $actorUserId, auditEntityType: 'supplier_payment',
        );
    }

    /**
     * Void the bank-book line the Bank step created (ACC-004: void,
     * never delete). A reconciled line cannot be unbanked -- undo the
     * reconciliation first.
     */
    public static function unbank(string $sourceType, string $sourceId, string $docType, string $actorUserId, string $reason, string $auditEntityType): BankTransaction
    {
        if (trim($reason) === '') {
            throw new PostingError('A reason is required to unbank a voucher.');
        }
        $txn = self::liveBankTransactionFor($sourceType, $sourceId);
        if ($txn === null) {
            throw new PostingError('This voucher is not in the bank book.');
        }
        if ($txn->is_reconciled) {
            throw new PostingError("{$txn->transaction_number} has been reconciled to a bank statement; undo the reconciliation before unbanking it.");
        }
        self::guard($txn->company_id, $txn->transaction_date, $docType, 'unbank');

        $txn->is_voided = true;
        $txn->void_reason = $reason;
        $txn->voided_at = Carbon::now();
        $txn->save();

        Audit::record(
            entityType: $auditEntityType,
            entityId: $sourceId,
            action: 'unbanked',
            actorUserId: $actorUserId,
            reason: $reason,
            details: "bank book line {$txn->transaction_number} voided",
            newValue: ['bank_transaction_id' => $txn->id, 'voided' => true],
        );

        return $txn;
    }

    // §7 Status for list screens.

    /** source_id -> ['gl_status' => posted|reversed, 'gl_voucher_number' => ...]. */
    public static function glStatusMap(string $companyId, string $sourceType): array
    {
        $rows = JournalEntry::where('company_id', $companyId)->where('source_type', $sourceType)->orderBy('created_at')->get();
        $out = [];
        foreach ($rows as $row) {
            if ($row->status !== JournalEntry::STATUS_REVERSED) {
                $out[$row->source_id] = ['gl_status' => 'posted', 'gl_voucher_number' => $row->voucher_number];
            } elseif (! isset($out[$row->source_id])) {
                $out[$row->source_id] = ['gl_status' => 'reversed', 'gl_voucher_number' => $row->voucher_number];
            }
        }

        return $out;
    }

    /** source_id -> ['bank_status' => 'banked', 'bank_transaction_number' => ...] for vouchers with a live bank-book line. */
    public static function bankStatusMap(string $companyId, string $sourceType): array
    {
        return BankTransaction::where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('is_voided', false)
            ->get()
            ->mapWithKeys(fn (BankTransaction $t) => [$t->source_id => ['bank_status' => 'banked', 'bank_transaction_number' => $t->transaction_number]])
            ->all();
    }

    /**
     * Stamp gl_status/gl_voucher_number (and bank_* for RV/PV) onto
     * already-built presentation arrays, keyed by 'id'.
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function decorate(string $companyId, iterable $rows, string $sourceType, bool $withBank = false): array
    {
        $rows = collect($rows)->all();
        if ($rows === []) {
            return $rows;
        }
        $gl = self::glStatusMap($companyId, $sourceType);
        $bank = $withBank ? self::bankStatusMap($companyId, $sourceType) : [];

        return array_map(function (array $row) use ($gl, $bank) {
            $row = array_merge($row, $gl[$row['id']] ?? []);
            $row = array_merge($row, $bank[$row['id']] ?? []);

            return $row;
        }, $rows);
    }
}
