<?php

namespace App\Services;

use App\Exceptions\ARRuleViolation;
use App\Exceptions\InventoryRuleViolation;
use App\Exceptions\PostingError;
use App\Models\BankAccount;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes against Sales Invoices (BILL-003; open-business-
 * decisions.md #49, 2.7 / #38: "a Credit Note against an invoice
 * reverses its GST and ledger entries and counts in the GST Calculation;
 * above the customer's credit note limit it needs the owner's approval").
 *
 *  - Always against one Sales Invoice, with a reason: for whole lines or
 *    part quantities of its lines, or for an amount (Dennis, 2026-09-26,
 *    decision page). GST is worked out at the invoice's own rate and tax
 *    code; a credit note is in its invoice's currency and at its rate.
 *  - Within the customer's credit note approval limit it is issued
 *    straight away by whoever raises it (anyone with EDIT on Billing);
 *    above the limit, or while none is set, it waits for the owner.
 *  - Issuing it takes the next CN number (issued credit notes number
 *    without gaps), posts the invoice's entry in reverse to the General
 *    Ledger, puts back in stock any line ticked "goods returned" (at the
 *    cost it left at, into the chosen warehouse), and takes it off what
 *    the invoice still owes.
 *  - An invoice already paid can be credited too (2026-09-26): what the
 *    invoice no longer owes stays on the customer's account as credit,
 *    which Finance sets against another of their invoices or refunds
 *    with a Payment Voucher. Commission already earned on that paid part
 *    is taken back, as on a write-off.
 *  - A pending credit note can be rejected (with a reason) or withdrawn;
 *    either way it is kept, never deleted.
 */
class CreditNotes
{
    /** Only the owner approves a credit note above the customer's limit (BILL-003). */
    public const ROUTINE_APPROVER_ROLES = [User::ROLE_OWNER];

    /**
     * What a new credit note on this invoice may still take off, in the
     * invoice's own currency: its total, less credit notes already issued
     * or waiting on it -- paid or not (2026-09-26).
     */
    public static function creditableFx(Invoice $invoice, ?string $exceptNoteId = null): Money
    {
        $taken = CreditNote::where('invoice_id', $invoice->id)
            ->whereIn('status', [CreditNote::STATUS_PENDING, CreditNote::STATUS_ISSUED])
            ->when($exceptNoteId, fn ($q) => $q->where('id', '!=', $exceptNoteId))
            ->get()
            ->reduce(fn (Money $carry, CreditNote $n) => $carry->plus($n->fx('total_amount')), Money::of(0));
        $left = $invoice->status === Invoice::STATUS_WRITTEN_OFF ? Money::of(0) : $invoice->fx('total_amount')->minus($taken);

        return $left->toFloat() < 0 ? Money::of(0) : $left;
    }

    /** The same, in SGD at the invoice's rate -- what crediting all of it takes off exactly. */
    private static function creditableSgd(Invoice $invoice): Money
    {
        $taken = CreditNote::where('invoice_id', $invoice->id)
            ->whereIn('status', [CreditNote::STATUS_PENDING, CreditNote::STATUS_ISSUED])
            ->sum('total_amount_sgd');
        $left = Money::of($invoice->total_amount_sgd ?? 0)->minus(Money::of($taken ?: 0));

        return $left->toFloat() < 0 ? Money::of(0) : $left;
    }

    /** Whether only the owner may approve this one: above the customer's limit, or no limit set. */
    public static function needsOwner(CreditNote $note): bool
    {
        $limit = $note->customer?->credit_note_approval_limit_sgd;
        if ($limit === null) {
            return true;
        }

        return Money::of($note->total_amount_sgd)->toFloat() > Money::of($limit)->toFloat();
    }

    public static function canApprove(CreditNote $note, User $actor): bool
    {
        return $actor->role === User::ROLE_OWNER;
    }

    /**
     * @param  array<int, array{invoice_line_id: string, quantity: int|string, return_to_stock?: bool, warehouse_id?: ?string}>  $lines
     */
    public static function raise(Invoice $invoice, User $actor, ?string $netAmount, string $reason, array $lines = []): CreditNote
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ARRuleViolation('Say why this credit note is being given.');
        }
        if ($invoice->status === Invoice::STATUS_WRITTEN_OFF) {
            throw new ARRuleViolation("{$invoice->invoice_number} was written off, so there is nothing left on it to credit.");
        }
        $code = $invoice->currencyCode();

        // Lines (whole or part quantities), or an amount.
        $prepared = [];
        $netFx = Money::of(0);
        foreach (array_values($lines) as $i => $line) {
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $invoiceLine = InvoiceLine::where('invoice_id', $invoice->id)->find($line['invoice_line_id'] ?? null);
            if (! $invoiceLine) {
                throw new ARRuleViolation('That line is not on '.$invoice->invoice_number.'.');
            }
            $already = (int) CreditNoteLine::where('invoice_line_id', $invoiceLine->id)
                ->whereHas('creditNote', fn ($q) => $q->whereIn('status', [CreditNote::STATUS_PENDING, CreditNote::STATUS_ISSUED]))
                ->sum('quantity');
            if ($qty > $invoiceLine->quantity - $already) {
                throw new ARRuleViolation(sprintf('"%s": only %d can still be credited.', $invoiceLine->description, max(0, $invoiceLine->quantity - $already)));
            }
            $returns = (bool) ($line['return_to_stock'] ?? false);
            $warehouseId = $line['warehouse_id'] ?? $invoiceLine->warehouse_id;
            if ($returns && $invoiceLine->stock_item_id === null) {
                throw new ARRuleViolation("\"{$invoiceLine->description}\" is not a stock line, so there are no goods to return.");
            }
            if ($returns && ($warehouseId === null || ! Warehouse::where('company_id', $invoice->company_id)->whereKey($warehouseId)->exists())) {
                throw new ARRuleViolation("Pick the warehouse the returned \"{$invoiceLine->description}\" goes back into.");
            }
            $amountFx = Money::of($invoiceLine->unit_price_fx ?? $invoiceLine->unit_price_sgd)->multipliedBy($qty)->quantize();
            $netFx = $netFx->plus($amountFx);
            $prepared[] = [
                'company_id' => $invoice->company_id,
                'invoice_line_id' => $invoiceLine->id,
                'line_no' => $i + 1,
                'description' => $invoiceLine->description,
                'quantity' => $qty,
                'amount_fx' => $amountFx->toString(),
                'amount_sgd' => Currency::toSgd($amountFx, $invoice->rate())->toString(),
                'return_to_stock' => $returns,
                'stock_item_id' => $returns ? $invoiceLine->stock_item_id : null,
                'warehouse_id' => $returns ? $warehouseId : null,
                'unit_cost_sgd' => $returns ? $invoiceLine->unit_cost_sgd : null,
            ];
        }
        if ($prepared === []) {
            $netFx = Money::of($netAmount ?? 0);
        }
        if ($netFx->toFloat() <= 0) {
            throw new ARRuleViolation('The credit note amount must be more than zero -- give an amount or at least one line.');
        }

        $rate = Money::of($invoice->gst_rate ?? 0);
        $gstFx = Tax::gstFor($netFx, $rate);
        $totalFx = $netFx->plus($gstFx);
        $creditable = self::creditableFx($invoice);
        if ($totalFx->toFloat() > $creditable->toFloat()) {
            throw new ARRuleViolation("{$code} {$totalFx->toString()} with GST is more than can be credited on {$invoice->invoice_number}: {$code} {$creditable->toString()} (its total, less credit notes already given or waiting on it).");
        }
        $gst = Currency::toSgd($gstFx, $invoice->rate());
        // Crediting all that is left takes exactly what is left in SGD, so no cent is stranded.
        $total = $totalFx->toString() === $creditable->toString() ? self::creditableSgd($invoice) : Currency::toSgd($totalFx, $invoice->rate());
        $net = $total->minus($gst);

        return DB::transaction(function () use ($invoice, $actor, $net, $gst, $total, $rate, $reason, $code, $netFx, $gstFx, $totalFx, $prepared) {
            $note = CreditNote::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'reason' => $reason,
                'amount_sgd' => $net->toString(),
                'tax_code' => $invoice->tax_code,
                'gst_rate' => $rate->toString(),
                'gst_amount_sgd' => $gst->toString(),
                'total_amount_sgd' => $total->toString(),
                'currency_code' => $code,
                'exchange_rate' => $invoice->rate(),
                'amount_fx' => $netFx->toString(),
                'gst_amount_fx' => $gstFx->toString(),
                'total_amount_fx' => $totalFx->toString(),
                'status' => CreditNote::STATUS_PENDING,
                'raised_by_user_id' => $actor->id,
                'raised_at' => now(),
            ]);
            foreach ($prepared as $row) {
                $note->lines()->create($row);
            }
            Audit::record(
                entityType: 'credit_note', entityId: $note->id, action: 'raised', actorUserId: $actor->id,
                details: "On {$invoice->invoice_number}: {$code} {$totalFx->toString()} incl. GST".($prepared ? ', '.count($prepared).' line(s)' : '')." -- {$reason}",
                newValue: ['invoice_number' => $invoice->invoice_number, 'amount_sgd' => $net->toString(), 'gst_amount_sgd' => $gst->toString(), 'total_amount_sgd' => $total->toString()],
            );

            // Within the customer's limit it is issued straight away
            // (Dennis, 2026-09-26: "Nobody, it issues straight away").
            if (! self::needsOwner($note)) {
                self::issue($note, $actor);
            }

            return $note->fresh();
        });
    }

    /** The owner approves one above the limit -- which issues it. */
    public static function approve(CreditNote $note, User $actor): CreditNote
    {
        if ($note->status !== CreditNote::STATUS_PENDING) {
            throw new ARRuleViolation('Only a credit note waiting for approval can be approved.');
        }
        if (! self::canApprove($note, $actor)) {
            $limit = $note->customer?->credit_note_approval_limit_sgd;
            throw new ARRuleViolation($limit === null
                ? "No credit note approval limit is set for {$note->customer?->name}, so the owner approves every credit note (BILL-003). Set a limit on its Company / Individual file."
                : "SGD {$note->total_amount_sgd} is above {$note->customer?->name}'s SGD ".Money::of($limit)->toString().' credit note approval limit -- the owner approves it (BILL-003).');
        }
        $invoice = $note->invoice;
        if ($note->fx('total_amount')->toFloat() > self::creditableFx($invoice, $note->id)->toFloat()) {
            throw new ARRuleViolation("{$invoice->invoice_number} has been credited since -- this credit note would take off more than its total. Reject this one and raise a smaller one.");
        }
        DB::transaction(fn () => self::issue($note, $actor));

        return $note->fresh();
    }

    /**
     * Issue: number, post, return goods, and apply it to its own invoice
     * up to what that still owes -- the rest stays on the customer's
     * account as credit.
     */
    private static function issue(CreditNote $note, User $actor): void
    {
        $invoice = $note->invoice;
        $note->credit_note_number = Numbering::next($note->company_id, 'credit_note');
        $note->status = CreditNote::STATUS_ISSUED;
        $note->decided_by_user_id = $actor->id;
        $note->decided_at = now();
        $note->issued_at = now();
        $note->save();
        try {
            Posting::postCreditNote($note, $actor->id);
        } catch (PostingError $e) {
            throw new ARRuleViolation($e->getMessage());
        }

        foreach ($note->lines()->where('return_to_stock', true)->get() as $line) {
            try {
                InventoryService::receiveStock(
                    $note->company_id, $line->stock_item_id, $line->warehouse_id, (int) $line->quantity,
                    (string) ($line->unit_cost_sgd ?? 0), referenceType: 'credit_note', referenceId: $note->id,
                    userId: $actor->id, notes: "Returned by the customer, {$note->credit_note_number}",
                );
            } catch (InventoryRuleViolation $e) {
                throw new ARRuleViolation($e->getMessage());
            }
        }

        $owed = $invoice->outstandingFx();
        $apply = $note->fx('total_amount')->toFloat() <= $owed->toFloat() ? $note->fx('total_amount') : $owed;
        if ($apply->toFloat() > 0) {
            $whole = $apply->toString() === $note->fx('total_amount')->toString();
            $sgd = $whole ? Money::of($note->total_amount_sgd) : Currency::toSgd($apply, $note->rate());
            CreditNoteApplication::create([
                'company_id' => $note->company_id, 'credit_note_id' => $note->id, 'invoice_id' => $invoice->id,
                'kind' => CreditNoteApplication::KIND_INVOICE, 'amount_fx' => $apply->toString(),
                'amount_sgd' => $sgd->toString(), 'note_amount_sgd' => $sgd->toString(),
                'applied_by_user_id' => $actor->id, 'applied_at' => now(),
            ]);
        }
        AccountsReceivableService::recalculateInvoiceStatus($invoice);

        // What the invoice no longer owed was paid: that part stays on the
        // customer's account, and commission earned on it is taken back.
        $onAccount = $note->fresh()->unappliedSgd();
        if ($onAccount->toFloat() > 0 && Money::of($invoice->total_amount_sgd)->toFloat() > 0) {
            CommissionService::createClawback(
                $note->company_id, $invoice, $actor->id,
                "Credit note {$note->credit_note_number} on a paid invoice",
                share: $onAccount->dividedBy((string) $invoice->total_amount_sgd),
            );
        }

        Audit::record(
            entityType: 'credit_note', entityId: $note->id, action: 'issued', actorUserId: $actor->id,
            details: "{$note->credit_note_number} on {$invoice->invoice_number}: SGD {$note->total_amount_sgd} incl. GST"
                .($onAccount->toFloat() > 0 ? "; SGD {$onAccount->toString()} left as credit on the customer's account" : ''),
            newValue: ['credit_note_number' => $note->credit_note_number, 'invoice_outstanding_sgd' => $invoice->fresh()->outstandingSgd()->toString(),
                'on_account_sgd' => $onAccount->toString()],
        );
    }

    /** Set credit left on the customer's account against another of their invoices, in the same currency. */
    public static function apply(CreditNote $note, Invoice $invoice, Money $amount, User $actor): CreditNoteApplication
    {
        if ($note->status !== CreditNote::STATUS_ISSUED) {
            throw new ARRuleViolation('Only an issued credit note has credit to use.');
        }
        if ($invoice->company_id !== $note->company_id || $invoice->customer_id !== $note->customer_id) {
            throw new ARRuleViolation('The credit can only go against the same customer\'s invoices.');
        }
        if ($invoice->currencyCode() !== $note->currencyCode()) {
            throw new ARRuleViolation("{$note->credit_note_number} is in {$note->currencyCode()} but {$invoice->invoice_number} is in {$invoice->currencyCode()}.");
        }
        $code = $note->currencyCode();
        $left = $note->unappliedFx();
        $owed = $invoice->outstandingFx();
        if ($amount->toFloat() <= 0) {
            throw new ARRuleViolation('The amount must be more than zero.');
        }
        if ($amount->toFloat() > $left->toFloat()) {
            throw new ARRuleViolation("Only {$code} {$left->toString()} of {$note->credit_note_number} is left.");
        }
        if ($amount->toFloat() > $owed->toFloat()) {
            throw new ARRuleViolation("{$invoice->invoice_number} only owes {$code} {$owed->toString()}.");
        }

        return DB::transaction(function () use ($note, $invoice, $amount, $left, $owed, $actor, $code) {
            // Each side at its own document's rate; a difference is a
            // realised exchange gain or loss (multi-currency).
            $noteSgd = $amount->toString() === $left->toString() ? $note->unappliedSgd() : Currency::toSgd($amount, $note->rate());
            $invoiceSgd = $amount->toString() === $owed->toString() ? $invoice->outstandingSgd() : Currency::toSgd($amount, $invoice->rate());
            $application = CreditNoteApplication::create([
                'company_id' => $note->company_id, 'credit_note_id' => $note->id, 'invoice_id' => $invoice->id,
                'kind' => CreditNoteApplication::KIND_INVOICE, 'amount_fx' => $amount->toString(),
                'amount_sgd' => $invoiceSgd->toString(), 'note_amount_sgd' => $noteSgd->toString(),
                'applied_by_user_id' => $actor->id, 'applied_at' => now(),
            ]);
            AccountsReceivableService::recalculateInvoiceStatus($invoice);
            $difference = $noteSgd->minus($invoiceSgd);
            if ($difference->toString() !== '0.00') {
                Posting::postExchangeDifference(
                    companyId: $note->company_id, receivable: true, allocationId: $application->id,
                    voucherNumber: "{$note->credit_note_number}-FX-{$invoice->invoice_number}", on: Carbon::today(),
                    difference: $difference, narration: "Exchange difference, {$note->credit_note_number} on {$invoice->invoice_number} ({$code})",
                    actorUserId: $actor->id,
                );
            }
            Audit::record('credit_note', $note->id, 'applied', $actor->id,
                details: "{$code} {$amount->toString()} of {$note->credit_note_number} set against {$invoice->invoice_number}");

            return $application;
        });
    }

    /**
     * Refund credit left on the customer's account with a Payment Voucher
     * (Dr AR / Cr bank), which then goes through approval and the Bank
     * step like any other.
     */
    public static function refund(CreditNote $note, string $bankAccountId, string $paymentDate, ?Money $amount, User $actor, ?string $reference = null): SupplierPayment
    {
        if ($note->status !== CreditNote::STATUS_ISSUED) {
            throw new ARRuleViolation('Only an issued credit note has credit to refund.');
        }
        $code = $note->currencyCode();
        $left = $note->unappliedFx();
        $amount ??= $left;
        if ($amount->toFloat() <= 0 || $amount->toFloat() > $left->toFloat()) {
            throw new ARRuleViolation("{$code} {$left->toString()} of {$note->credit_note_number} is left to refund.");
        }
        $bank = BankAccount::where('company_id', $note->company_id)->find($bankAccountId);
        if (! $bank) {
            throw new ARRuleViolation('Unknown bank account.');
        }
        $bankCurrency = Currency::code($bank->currency_code);
        if (! Currency::isBase($bankCurrency) && $bankCurrency !== $code) {
            throw new ARRuleViolation("That bank account is in {$bankCurrency}; the refund is in {$code}.");
        }

        return DB::transaction(function () use ($note, $bank, $paymentDate, $amount, $left, $actor, $code, $reference) {
            $rate = Currency::isBase($code) ? '1.000000' : (Currency::rateOn($note->company_id, $code, $paymentDate) ?? $note->rate());
            $pvSgd = Currency::toSgd($amount, $rate);
            $noteSgd = $amount->toString() === $left->toString() ? $note->unappliedSgd() : Currency::toSgd($amount, $note->rate());
            $pv = SupplierPayment::create([
                'company_id' => $note->company_id,
                'supplier_id' => $note->customer_id,
                'voucher_number' => Numbering::next($note->company_id, 'payment'),
                'payment_date' => $paymentDate,
                'amount_sgd' => $pvSgd->toString(),
                'currency_code' => $code,
                'exchange_rate' => $rate,
                'amount_fx' => $amount->toString(),
                'method' => SupplierPayment::METHOD_BANK_TRANSFER,
                'reference' => $reference,
                'notes' => "Refund of credit note {$note->credit_note_number}",
                'bank_account_id' => $bank->id,
                'paid_by_user_id' => $actor->id,
                'refund_credit_note_id' => $note->id,
            ]);
            try {
                Posting::postSupplierPayment($pv, $actor->id);
            } catch (PostingError $e) {
                throw new ARRuleViolation($e->getMessage());
            }
            $application = CreditNoteApplication::create([
                'company_id' => $note->company_id, 'credit_note_id' => $note->id, 'supplier_payment_id' => $pv->id,
                'kind' => CreditNoteApplication::KIND_REFUND, 'amount_fx' => $amount->toString(),
                'amount_sgd' => $pvSgd->toString(), 'note_amount_sgd' => $noteSgd->toString(),
                'applied_by_user_id' => $actor->id, 'applied_at' => now(),
            ]);
            $difference = $noteSgd->minus($pvSgd);
            if ($difference->toString() !== '0.00') {
                Posting::postExchangeDifference(
                    companyId: $note->company_id, receivable: true, allocationId: $application->id,
                    voucherNumber: "{$pv->voucher_number}-FX-{$note->credit_note_number}", on: Carbon::parse($paymentDate),
                    difference: $difference, narration: "Exchange difference, refund {$pv->voucher_number} of {$note->credit_note_number} ({$code})",
                    actorUserId: $actor->id,
                );
            }
            // Signatories above the Bank Authority amount, as for any PV.
            ApprovalService::submitForApproval(
                $note->company_id, 'payment_voucher', $pv->id, $actor->id, $pvSgd->toString(),
                bankAccountId: $bank->id,
                summary: "Payment Voucher {$pv->voucher_number}, SGD {$pvSgd->toString()} refund of {$note->credit_note_number} to {$note->customer?->name}",
            );
            Audit::record('credit_note', $note->id, 'refunded', $actor->id,
                details: "{$code} {$amount->toString()} of {$note->credit_note_number} refunded by {$pv->voucher_number}");
            Audit::record('supplier_payment', $pv->id, 'recorded', $actor->id,
                details: "{$pv->voucher_number}: refund of {$note->credit_note_number}, SGD {$pvSgd->toString()}");

            return $pv;
        });
    }

    public static function reject(CreditNote $note, User $actor, string $reason): CreditNote
    {
        if ($note->status !== CreditNote::STATUS_PENDING) {
            throw new ARRuleViolation('Only a credit note waiting for approval can be rejected.');
        }
        if (! self::canApprove($note, $actor)) {
            throw new ARRuleViolation('Only someone who may approve this credit note can reject it (BILL-003).');
        }
        if (trim($reason) === '') {
            throw new ARRuleViolation('Say why it is rejected.');
        }
        $note->status = CreditNote::STATUS_REJECTED;
        $note->decided_by_user_id = $actor->id;
        $note->decided_at = now();
        $note->decision_note = trim($reason);
        $note->save();
        Audit::record(entityType: 'credit_note', entityId: $note->id, action: 'rejected', actorUserId: $actor->id, reason: trim($reason));

        return $note;
    }

    /** Taken back before a decision -- by whoever raised it, or anyone who could approve it. */
    public static function withdraw(CreditNote $note, User $actor): CreditNote
    {
        if ($note->status !== CreditNote::STATUS_PENDING) {
            throw new ARRuleViolation('Only a credit note waiting for approval can be withdrawn.');
        }
        if ($note->raised_by_user_id !== $actor->id && ! self::canApprove($note, $actor)) {
            throw new ARRuleViolation('Only whoever raised it, or someone who may approve it, can withdraw a credit note.');
        }
        $note->status = CreditNote::STATUS_WITHDRAWN;
        $note->decided_by_user_id = $actor->id;
        $note->decided_at = now();
        $note->save();
        Audit::record(entityType: 'credit_note', entityId: $note->id, action: 'withdrawn', actorUserId: $actor->id);

        return $note;
    }
}
