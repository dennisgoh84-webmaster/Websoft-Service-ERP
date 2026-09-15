<?php

namespace App\Services;

use App\Exceptions\ContractRuleViolation;
use App\Exceptions\QuotationRuleViolation;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Sales Quotation business logic: totals and the accept -> auto-
 * Contract conversion. Mirrors backend/app/services/quotations.py
 * exactly -- see App\Models\Quotation's docstring for the confirmed
 * 2026-09-10 splitting rule (hourly lines -> one Service Support
 * contract; every other line -> one Annual contract).
 */
class QuotationService
{
    /**
     * Recomputes the document-level net/GST/total from its lines.
     * Single GST rate per document, same pattern as Invoice. Caller
     * is responsible for saving the quotation afterwards -- mirrors
     * the Python function, which only mutates the ORM object and
     * leaves db.commit() to its own caller.
     */
    public static function recomputeTotals(Quotation $quotation): void
    {
        $net = $quotation->lines->reduce(
            fn (Money $carry, $line) => $carry->plus(Money::of($line->line_total_sgd)),
            Money::of(0),
        );
        [$taxCode, $rate, $gst, $total] = Tax::applyGst($quotation->company_id, $net);

        $quotation->amount_sgd = $net->toString();
        $quotation->tax_code = $taxCode;
        $quotation->gst_rate = $rate->toString();
        $quotation->gst_amount_sgd = $gst->toString();
        $quotation->total_amount_sgd = $total->toString();
    }

    private static function isHourly(?string $unitOfMeasure): bool
    {
        return in_array(strtolower(trim($unitOfMeasure ?? '')), ['hour', 'hours'], true);
    }

    /**
     * Marks the quotation Accepted and attempts to auto-create
     * Contract(s) from it -- confirmed 2026-09-10: a quotation's lines
     * split by unit of measure into up to two separate contracts,
     * never one blending both:
     *   - Lines whose unit is "Hours"/"Hour" -> one SERVICE_SUPPORT
     *     contract, summing their hours and their value. SRV-002/012's
     *     10-hour minimum still applies with no override, so this half
     *     may legitimately not convert if there aren't enough hours.
     *   - Every other line -> one ANNUAL contract (term-only, standard
     *     12-month duration), summing their value. No minimum applies.
     * A quotation with only one kind of line converts to just that one
     * contract; a quotation with neither (empty, in practice
     * impossible since a quotation needs at least one line) converts
     * to neither. Returns a message describing exactly what happened
     * to each half. Caller is responsible for saving the quotation
     * afterwards, same as recomputeTotals() above -- see
     * QuotationController::accept()'s DB::transaction.
     */
    // ── Status transitions (settled 2026-09-15, BILL-006) ───────────
    //
    // Each one checks the state it moves FROM and throws
    // QuotationRuleViolation otherwise; the controller turns that into
    // a 409. Callers save the quotation afterwards, inside their own
    // transaction, same as acceptQuotation() below.

    /** draft -> pending_approval. Anyone who can edit quotations. */
    public static function submitForApproval(Quotation $quotation, string $actorUserId): void
    {
        self::requireStatus($quotation, [Quotation::STATUS_DRAFT], 'submitted for approval');
        $quotation->status = Quotation::STATUS_PENDING_APPROVAL;
        $quotation->submitted_at = Carbon::now('UTC');
        $quotation->submitted_by_user_id = $actorUserId;
        $quotation->returned_reason = null;
        Audit::record('quotation', $quotation->id, 'submitted_for_approval', $actorUserId);
    }

    /**
     * pending_approval -> approved. BILL-006: the Sales Manager, with no
     * value threshold below which a quotation skips this; the owner
     * can always stand in, as on every other approval in this system.
     */
    public static function approve(Quotation $quotation, User $approver): void
    {
        if (! in_array($approver->role, Quotation::APPROVER_ROLES, true)) {
            throw new QuotationRuleViolation(
                'Only the Sales Manager or the owner can approve a quotation (BILL-006).'
            );
        }
        self::requireStatus($quotation, [Quotation::STATUS_PENDING_APPROVAL], 'approved');
        $quotation->status = Quotation::STATUS_APPROVED;
        $quotation->approved_at = Carbon::now('UTC');
        $quotation->approved_by_user_id = $approver->id;
        Audit::record('quotation', $quotation->id, 'approved', $approver->id);
    }

    /** pending_approval -> draft, with the reason, so it can be fixed and resubmitted. */
    public static function sendBack(Quotation $quotation, User $approver, string $reason): void
    {
        if (! in_array($approver->role, Quotation::APPROVER_ROLES, true)) {
            throw new QuotationRuleViolation(
                'Only the Sales Manager or the owner can send a quotation back (BILL-006).'
            );
        }
        self::requireStatus($quotation, [Quotation::STATUS_PENDING_APPROVAL], 'sent back');
        $quotation->status = Quotation::STATUS_DRAFT;
        $quotation->returned_reason = trim($reason);
        $quotation->submitted_at = null;
        $quotation->submitted_by_user_id = null;
        Audit::record('quotation', $quotation->id, 'sent_back', $approver->id, reason: $reason);
    }

    /** approved -> sent: it is now with the customer, "pending confirmation by client". */
    public static function send(Quotation $quotation, string $actorUserId): void
    {
        self::requireStatus($quotation, [Quotation::STATUS_APPROVED], 'sent');
        $quotation->status = Quotation::STATUS_SENT;
        $quotation->sent_at = Carbon::now('UTC');
        Audit::record('quotation', $quotation->id, 'sent', $actorUserId);
    }

    /**
     * -> rejected, from any state before acceptance: a customer can
     * decline, or Sales can withdraw, at any point up to acceptance.
     */
    public static function reject(Quotation $quotation, string $actorUserId): void
    {
        self::requireStatus($quotation, [
            Quotation::STATUS_DRAFT, Quotation::STATUS_PENDING_APPROVAL,
            Quotation::STATUS_APPROVED, Quotation::STATUS_SENT,
        ], 'rejected');
        $quotation->status = Quotation::STATUS_REJECTED;
        Audit::record('quotation', $quotation->id, 'rejected', $actorUserId);
    }

    /** @param  list<string>  $allowed */
    private static function requireStatus(Quotation $quotation, array $allowed, string $verb): void
    {
        if (! in_array($quotation->status, $allowed, true)) {
            throw new QuotationRuleViolation(
                "A {$quotation->status} quotation cannot be {$verb}."
            );
        }
    }

    public static function acceptQuotation(Quotation $quotation, string $actorUserId): string
    {
        // Only a quotation the customer actually has can be accepted --
        // acceptance is their confirmation of what was sent (BILL-006
        // put approval before sending; this keeps acceptance after it).
        self::requireStatus($quotation, [Quotation::STATUS_SENT], 'accepted');
        $quotation->status = Quotation::STATUS_ACCEPTED;

        $hourlyLines = $quotation->lines->filter(fn ($line) => self::isHourly($line->unit_of_measure));
        $otherLines = $quotation->lines->reject(fn ($line) => self::isHourly($line->unit_of_measure));

        // A renewal quotation (raised from an expiring contract, SALES-006)
        // renews THAT contract through the same path the Renew form
        // uses, so SRV-010/016 apply exactly; it never creates a fresh
        // one beside it.
        if ($quotation->renews_contract_id) {
            return self::acceptRenewal($quotation, $hourlyLines, $otherLines, $actorUserId);
        }

        $messages = [];

        if ($hourlyLines->isNotEmpty()) {
            $hourlyQty = $hourlyLines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->quantity)), Money::of(0));
            $hourlyValue = $hourlyLines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->line_total_sgd)), Money::of(0));
            try {
                $contract = ContractService::createContract(
                    companyId: $quotation->company_id,
                    customerId: $quotation->customer_id,
                    contractedHours: $hourlyQty->toFloat(),
                    contractValueSgd: $hourlyValue->toFloat(),
                    startDate: Carbon::today()->toDateString(),
                    actorUserId: $actorUserId,
                    contractKind: Contract::KIND_SERVICE_SUPPORT,
                );
                $contract->quotation_id = $quotation->id;
                $contract->save();
                $quotation->converted_contract_id = $contract->id;
                $messages[] = sprintf('Service Support contract created (%s hrs).', self::formatG($hourlyQty->toFloat()));
            } catch (ContractRuleViolation $e) {
                $messages[] = "Hourly lines not converted to a contract: {$e->getMessage()}";
            }
        }

        if ($otherLines->isNotEmpty()) {
            $otherValue = $otherLines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->line_total_sgd)), Money::of(0));
            $annualContract = ContractService::createContract(
                companyId: $quotation->company_id,
                customerId: $quotation->customer_id,
                contractedHours: 0,
                contractValueSgd: $otherValue->toFloat(),
                startDate: Carbon::today()->toDateString(),
                actorUserId: $actorUserId,
                contractKind: Contract::KIND_ANNUAL,
            );
            $annualContract->quotation_id = $quotation->id;
            $annualContract->save();
            $quotation->converted_annual_contract_id = $annualContract->id;
            $messages[] = sprintf('Annual contract created (SGD %.2f, 12-month term).', $otherValue->toFloat());
        }

        $message = $messages ? 'Quotation accepted. '.implode(' ', $messages) : 'Quotation accepted.';

        Audit::record('quotation', $quotation->id, 'accepted', $actorUserId, details: $message);

        return $message;
    }

    /**
     * Accepting a renewal quotation: the prior contract is renewed with
     * the quotation's hours (its hourly lines) and value (all lines),
     * of the prior contract's own kind. If SRV-010/016 refuse -- most
     * often because the SRV-016 2-week backdating window has passed
     * and SRV-018 wants a human decision -- the acceptance stands, the
     * contract is left as it was, and the message says so, so the
     * Renew form (with this quotation picked) is the next step.
     */
    private static function acceptRenewal(Quotation $quotation, $hourlyLines, $otherLines, string $actorUserId): string
    {
        $prior = Contract::findOrFail($quotation->renews_contract_id);
        $hours = $hourlyLines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->quantity)), Money::of(0));
        $value = $quotation->lines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->line_total_sgd)), Money::of(0));

        try {
            $new = ContractService::renewContract(
                priorContract: $prior,
                contractedHours: $hours->toFloat(),
                contractValueSgd: $value->toFloat(),
                actorUserId: $actorUserId,
            );
        } catch (ContractRuleViolation $e) {
            $message = "Quotation accepted, but {$prior->contract_number} was not renewed: {$e->getMessage()} "
                .'Renew it from the contract page, picking this quotation.';
            Audit::record('quotation', $quotation->id, 'accepted', $actorUserId, details: $message);

            return $message;
        }

        $new->quotation_id = $quotation->id;
        $new->save();
        if ($new->contract_kind === Contract::KIND_SERVICE_SUPPORT) {
            $quotation->converted_contract_id = $new->id;
        } else {
            $quotation->converted_annual_contract_id = $new->id;
        }

        $message = sprintf(
            'Quotation accepted. %s renewed as %s (%s).',
            $prior->contract_number,
            $new->contract_number,
            $new->contract_kind === Contract::KIND_SERVICE_SUPPORT
                ? self::formatG($hours->toFloat()).' hrs'
                : sprintf('SGD %.2f, 12-month term', $value->toFloat()),
        );
        Audit::record('quotation', $quotation->id, 'accepted', $actorUserId, details: $message);

        return $message;
    }

    /** PHP equivalent of Python's f"{value:g}" formatting used in the accept-message text. */
    private static function formatG(float $value): string
    {
        return sprintf('%g', $value);
    }
}
