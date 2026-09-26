<?php

namespace App\Services;

use App\Exceptions\ARRuleViolation;
use App\Exceptions\PostingError;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes against Sales Invoices (BILL-003; open-business-
 * decisions.md #49, 2.7 / #38: "a Credit Note against an invoice
 * reverses its GST and ledger entries and counts in the GST Calculation;
 * above the customer's credit note limit it needs the owner's approval").
 *
 *  - Raised against one invoice, with a reason, for a net amount; GST is
 *    worked out at the invoice's own rate and tax code.
 *  - Approved by Finance or the Sales Manager (Cherish) for routine
 *    credit notes -- the owner too -- when its total is within the
 *    customer's credit note approval limit on its Company / Individual
 *    file; above that limit, or while none is set, by the owner only.
 *  - Approval issues it: it takes the next CN number (issued credit notes
 *    number without gaps), posts the invoice's entry in reverse to the
 *    General Ledger, and takes its total off what the invoice still owes.
 *  - A pending credit note can be rejected (with a reason) or withdrawn;
 *    either way it is kept, never deleted.
 *
 * Defaults taken 2026-09-26, recorded in open-business-decisions.md #52
 * and put to Dennis on the decision page:
 *  - A credit note takes off at most what the invoice still owes, less
 *    credit notes already waiting on it -- so a fully paid invoice is not
 *    credited until how a refund or customer credit works is decided.
 *    Nothing to claw back follows: commission is earned on receipts, and
 *    a credit note only ever takes off what has not been received.
 *  - It moves no stock: goods a customer sends back are put back on the
 *    stock screens separately.
 */
class CreditNotes
{
    /** BILL-003's routine approvers: Finance and the Sales Manager -- and the owner, who may approve anything. */
    public const ROUTINE_APPROVER_ROLES = [User::ROLE_OWNER, User::ROLE_FINANCE, User::ROLE_SALES_MANAGER];

    /** What a new credit note on this invoice may still take off: what it owes, less credit notes already waiting on it. */
    public static function creditableSgd(Invoice $invoice, ?string $exceptNoteId = null): Money
    {
        $pending = CreditNote::where('invoice_id', $invoice->id)->where('status', CreditNote::STATUS_PENDING)
            ->when($exceptNoteId, fn ($q) => $q->where('id', '!=', $exceptNoteId))
            ->get()
            ->reduce(fn (Money $carry, CreditNote $n) => $carry->plus(Money::of($n->total_amount_sgd)), Money::of(0));
        $left = $invoice->outstandingSgd()->minus($pending);

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
        if (self::needsOwner($note)) {
            return $actor->role === User::ROLE_OWNER;
        }

        return in_array($actor->role, self::ROUTINE_APPROVER_ROLES, true);
    }

    public static function raise(Invoice $invoice, User $actor, string $netAmount, string $reason): CreditNote
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ARRuleViolation('Say why this credit note is being given.');
        }
        if ($invoice->status === Invoice::STATUS_WRITTEN_OFF) {
            throw new ARRuleViolation("{$invoice->invoice_number} was written off, so there is nothing left on it to credit.");
        }
        $net = Money::of($netAmount);
        if ($net->toFloat() <= 0) {
            throw new ARRuleViolation('The credit note amount must be more than zero.');
        }
        $rate = Money::of($invoice->gst_rate ?? 0);
        $gst = Tax::gstFor($net, $rate);
        $total = $net->plus($gst);
        $creditable = self::creditableSgd($invoice);
        if ($total->toFloat() > $creditable->toFloat()) {
            throw new ARRuleViolation("SGD {$total->toString()} with GST is more than can be credited on {$invoice->invoice_number}: SGD {$creditable->toString()} (what it still owes, less credit notes already waiting on it).");
        }

        return DB::transaction(function () use ($invoice, $actor, $net, $gst, $total, $rate, $reason) {
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
                'status' => CreditNote::STATUS_PENDING,
                'raised_by_user_id' => $actor->id,
                'raised_at' => now(),
            ]);
            Audit::record(
                entityType: 'credit_note', entityId: $note->id, action: 'raised', actorUserId: $actor->id,
                details: "On {$invoice->invoice_number}: SGD {$total->toString()} incl. GST -- {$reason}",
                newValue: ['invoice_number' => $invoice->invoice_number, 'amount_sgd' => $net->toString(), 'gst_amount_sgd' => $gst->toString(), 'total_amount_sgd' => $total->toString()],
            );

            return $note;
        });
    }

    /** Approve -- which issues it: numbered, posted, and taken off the invoice. */
    public static function approve(CreditNote $note, User $actor): CreditNote
    {
        if ($note->status !== CreditNote::STATUS_PENDING) {
            throw new ARRuleViolation('Only a credit note waiting for approval can be approved.');
        }
        if (! self::canApprove($note, $actor)) {
            $limit = $note->customer?->credit_note_approval_limit_sgd;
            throw new ARRuleViolation($limit === null
                ? "No credit note approval limit is set for {$note->customer?->name}, so the owner approves every credit note (BILL-003). Set a limit on its Company / Individual file."
                : (self::needsOwner($note)
                    ? "SGD {$note->total_amount_sgd} is above {$note->customer?->name}'s SGD ".Money::of($limit)->toString().' credit note approval limit -- the owner approves it (BILL-003).'
                    : 'Credit notes are approved by Finance, the Sales Manager or the owner (BILL-003).'));
        }
        $invoice = $note->invoice;
        if (Money::of($note->total_amount_sgd)->toFloat() > self::creditableSgd($invoice, $note->id)->toFloat()) {
            throw new ARRuleViolation("{$invoice->invoice_number} now owes less than this credit note takes off -- it was paid or credited since. Reject this one and raise a smaller one.");
        }

        DB::transaction(function () use ($note, $invoice, $actor) {
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
            AccountsReceivableService::recalculateInvoiceStatus($invoice);
            Audit::record(
                entityType: 'credit_note', entityId: $note->id, action: 'issued', actorUserId: $actor->id,
                details: "{$note->credit_note_number} on {$invoice->invoice_number}: SGD {$note->total_amount_sgd} incl. GST",
                newValue: ['credit_note_number' => $note->credit_note_number, 'invoice_outstanding_sgd' => $invoice->fresh()->outstandingSgd()->toString()],
            );
        });

        return $note->fresh();
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
