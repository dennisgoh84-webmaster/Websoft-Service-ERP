<?php

namespace App\Services;

use App\Exceptions\BillingRuleViolation;
use App\Exceptions\ContractRuleViolation;
use App\Exceptions\InventoryRuleViolation;
use App\Exceptions\QuotationRuleViolation;
use App\Models\Contract;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\StockItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales Quotation business logic: totals and what accepting one
 * creates -- see acceptQuotation(): product lines -> a Sales Invoice
 * issued on acceptance (decision 11.2, 2026-09-26); hourly lines -> one
 * Service Support contract; every other line -> one Annual contract
 * (2026-09-10).
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
        // Multi-currency: GST worked out in the quotation's currency, and
        // each figure kept in SGD at its rate.
        $netFx = $quotation->lines->reduce(
            fn (Money $carry, $line) => $carry->plus(Money::of($line->line_total_fx ?? $line->line_total_sgd)),
            Money::of(0),
        );
        [$taxCode, $rate, $gstFx, $totalFx] = Tax::applyGst($quotation->company_id, $netFx);
        $gst = Currency::toSgd($gstFx, $quotation->rate());

        $quotation->amount_sgd = $net->toString();
        $quotation->tax_code = $taxCode;
        $quotation->gst_rate = $rate->toString();
        $quotation->gst_amount_sgd = $gst->toString();
        $quotation->total_amount_sgd = $net->plus($gst)->toString();
        $quotation->amount_fx = $netFx->toString();
        $quotation->gst_amount_fx = $gstFx->toString();
        $quotation->total_amount_fx = $totalFx->toString();
    }

    private static function isHourly(?string $unitOfMeasure): bool
    {
        return in_array(strtolower(trim($unitOfMeasure ?? '')), ['hour', 'hours'], true);
    }

    /**
     * A product line (decision 11.2, Dennis 2026-09-26: "Lines whose
     * catalog product is a Product"): its catalog item is Product-type
     * (hardware, a licence sold outright). Service-type items and
     * free-text lines are not.
     */
    public static function isProductLine(QuotationLine $line): bool
    {
        return $line->product_id !== null && $line->product?->product_type === Product::TYPE_PRODUCT;
    }

    /** The stock item a product line draws from, if the product is kept in stock. */
    public static function stockItemFor(QuotationLine $line): ?StockItem
    {
        if (! self::isProductLine($line)) {
            return null;
        }

        return StockItem::where('company_id', $line->product->company_id)
            ->where('product_id', $line->product_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->first();
    }

    /**
     * Marks the quotation Accepted and creates what it sold.
     *
     * Product lines (isProductLine) -- decision 11.2, Dennis 2026-09-26,
     * "Straight away on acceptance" -- become one Sales Invoice issued
     * there and then, through the same path as Raise Sales Invoice: a
     * line whose product is kept in stock takes it from $warehouseId at
     * its weighted average cost, and if any warehouse is short the whole
     * acceptance is refused (stock is never negative). Quantities must
     * be whole. The invoice carries the quotation's prospect.
     *
     * The remaining lines split as confirmed 2026-09-10, by unit of
     * measure, into up to two separate contracts, never one blending
     * both:
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
        $quotation->submitted_at = Carbon::now();
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
        $quotation->approved_at = Carbon::now();
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
        $quotation->sent_at = Carbon::now();
        Audit::record('quotation', $quotation->id, 'sent', $actorUserId);
    }

    /**
     * sent -> to_revise: the customer wants changes. What they asked
     * for is recorded so the revision is raised against it.
     */
    public static function markToRevise(Quotation $quotation, string $actorUserId, string $reason): void
    {
        self::requireStatus($quotation, [Quotation::STATUS_SENT], 'marked to revise');
        $quotation->status = Quotation::STATUS_TO_REVISE;
        $quotation->to_revise_at = Carbon::now();
        $quotation->revision_reason = trim($reason);
        Audit::record('quotation', $quotation->id, 'to_revise', $actorUserId, reason: $reason);
    }

    /**
     * Raise the revision: a new DRAFT quotation with the same customer,
     * notes and lines (and the same contract to renew, if it was a
     * renewal quotation), linked back to this one. Lines are not
     * editable once a quotation exists, so a revision is always a new
     * document -- which is also what the customer receives: a new
     * number, never a silently altered one. It goes through approval
     * and sending again from the top.
     */
    public static function createRevision(Quotation $original, string $actorUserId): Quotation
    {
        self::requireStatus($original, [Quotation::STATUS_TO_REVISE], 'revised');
        if ($existing = $original->revisions()->whereIn('status', Quotation::OPEN_STATUSES)->first()) {
            throw new QuotationRuleViolation(
                "{$original->quotation_number} already has an open revision, {$existing->quotation_number} ({$existing->status})."
            );
        }

        return DB::transaction(function () use ($original, $actorUserId) {
            $revision = Quotation::create([
                'company_id' => $original->company_id,
                'quotation_number' => Numbering::next($original->company_id, 'quotation'),
                'customer_id' => $original->customer_id,
                'quotation_date' => Carbon::today()->toDateString(),
                'valid_until' => null,
                'notes' => $original->notes,
                'created_by_user_id' => $actorUserId,
                'renews_contract_id' => $original->renews_contract_id,
                'revised_from_quotation_id' => $original->id,
                'prospect_id' => $original->prospect_id,
                'currency_code' => $original->currencyCode(),
                'exchange_rate' => $original->rate(),
            ]);
            foreach ($original->lines as $line) {
                QuotationLine::create([
                    'quotation_id' => $revision->id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'unit_of_measure' => $line->unit_of_measure,
                    'quantity' => $line->quantity,
                    'unit_price_sgd' => $line->unit_price_sgd,
                    'line_total_sgd' => $line->line_total_sgd,
                    'unit_price_fx' => $line->unit_price_fx,
                    'line_total_fx' => $line->line_total_fx,
                    'reference_code_id' => $line->reference_code_id,
                    'cost_sgd' => $line->cost_sgd,
                ]);
            }
            $revision->load('lines');
            self::recomputeTotals($revision);
            $revision->save();

            Audit::record('quotation', $revision->id, 'created', $actorUserId,
                details: "{$revision->quotation_number}: revision of {$original->quotation_number}",
                newValue: ['revised_from_quotation_id' => $original->id]);
            Audit::record('quotation', $original->id, 'revised', $actorUserId,
                details: "revised as {$revision->quotation_number}",
                newValue: ['revision_id' => $revision->id]);

            return $revision;
        });
    }

    /**
     * -> rejected, from any state before acceptance: a customer can
     * decline, or Sales can withdraw, at any point up to acceptance.
     */
    public static function reject(Quotation $quotation, string $actorUserId): void
    {
        self::requireStatus($quotation, [
            Quotation::STATUS_DRAFT, Quotation::STATUS_PENDING_APPROVAL,
            Quotation::STATUS_APPROVED, Quotation::STATUS_SENT, Quotation::STATUS_TO_REVISE,
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

    public static function acceptQuotation(Quotation $quotation, string $actorUserId, ?string $warehouseId = null): string
    {
        // Only a quotation the customer actually has can be accepted --
        // acceptance is their confirmation of what was sent (BILL-006
        // put approval before sending; this keeps acceptance after it).
        self::requireStatus($quotation, [Quotation::STATUS_SENT], 'accepted');
        $quotation->status = Quotation::STATUS_ACCEPTED;
        self::markProspectWon($quotation, $actorUserId);

        $quotation->loadMissing('lines.product');
        $productLines = $quotation->lines->filter(fn ($line) => self::isProductLine($line));
        $contractLines = $quotation->lines->reject(fn ($line) => self::isProductLine($line));
        $hourlyLines = $contractLines->filter(fn ($line) => self::isHourly($line->unit_of_measure));
        $otherLines = $contractLines->reject(fn ($line) => self::isHourly($line->unit_of_measure));

        $messages = [];
        if ($productLines->isNotEmpty()) {
            $messages[] = self::invoiceProductLines($quotation, $productLines, $warehouseId, $actorUserId);
        }

        // A renewal quotation (raised from an expiring contract, SALES-006)
        // renews THAT contract through the same path the Renew form
        // uses, so SRV-010/016 apply exactly; it never creates a fresh
        // one beside it.
        if ($quotation->renews_contract_id && $contractLines->isNotEmpty()) {
            return self::acceptRenewal($quotation, $hourlyLines, $contractLines, $actorUserId, $messages);
        }

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
     * The customer accepting a quotation wins its prospect (Dennis,
     * 2026-09-26, decision 46.1: "Yes"). Whatever stage it was at --
     * even Lost -- it becomes Won; an already-Won prospect is left as it
     * is. Audited on the prospect with the quotation that won it.
     */
    private static function markProspectWon(Quotation $quotation, string $actorUserId): void
    {
        if ($quotation->prospect_id === null) {
            return;
        }
        $prospect = Prospect::find($quotation->prospect_id);
        if ($prospect === null || $prospect->status === Prospect::STATUS_WON) {
            return;
        }

        $old = ['status' => $prospect->status, 'lost_reason' => $prospect->lost_reason];
        $prospect->status = Prospect::STATUS_WON;
        $prospect->lost_reason = null;
        $prospect->last_edited_by_user_id = $actorUserId;
        $prospect->updated_at = Carbon::now();
        $prospect->save();

        Audit::record(
            'prospect', $prospect->id, 'won', $actorUserId,
            companyId: $prospect->company_id,
            details: "Quotation {$quotation->quotation_number} accepted by the customer",
            oldValue: $old,
            newValue: ['status' => Prospect::STATUS_WON],
        );
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
    private static function acceptRenewal(Quotation $quotation, $hourlyLines, $contractLines, string $actorUserId, array $before = []): string
    {
        $prior = Contract::findOrFail($quotation->renews_contract_id);
        $hours = $hourlyLines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->quantity)), Money::of(0));
        // Product lines were invoiced on acceptance, so they are not part of the renewed contract's value.
        $value = $contractLines->reduce(fn (Money $c, $l) => $c->plus(Money::of($l->line_total_sgd)), Money::of(0));
        $invoiced = $before ? ' '.implode(' ', $before) : '';

        try {
            $new = ContractService::renewContract(
                priorContract: $prior,
                contractedHours: $hours->toFloat(),
                contractValueSgd: $value->toFloat(),
                actorUserId: $actorUserId,
            );
        } catch (ContractRuleViolation $e) {
            $message = "Quotation accepted.{$invoiced} But {$prior->contract_number} was not renewed: {$e->getMessage()} "
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
            'Quotation accepted.%s %s renewed as %s (%s).',
            $invoiced,
            $prior->contract_number,
            $new->contract_number,
            $new->contract_kind === Contract::KIND_SERVICE_SUPPORT
                ? self::formatG($hours->toFloat()).' hrs'
                : sprintf('SGD %.2f, 12-month term', $value->toFloat()),
        );
        Audit::record('quotation', $quotation->id, 'accepted', $actorUserId, details: $message);

        return $message;
    }

    /**
     * Issues the Sales Invoice for a quotation's product lines (decision
     * 11.2). Refusals -- a part quantity, a stock line with no warehouse
     * named, not enough stock -- throw QuotationRuleViolation so the
     * whole acceptance rolls back.
     */
    private static function invoiceProductLines(Quotation $quotation, $productLines, ?string $warehouseId, string $actorUserId): string
    {
        $lines = [];
        foreach ($productLines as $line) {
            $qty = Money::of($line->quantity);
            if ($qty->toString() !== $qty->quantize(0)->toString()) {
                throw new QuotationRuleViolation(sprintf(
                    '"%s" is quoted as %s; a Sales Invoice line needs a whole quantity. Revise the quotation first.',
                    $line->description, self::formatG($qty->toFloat()),
                ));
            }
            $stockItem = self::stockItemFor($line);
            if ($stockItem !== null && $warehouseId === null) {
                throw new QuotationRuleViolation(
                    "\"{$line->description}\" is a stock item: pick the warehouse its stock leaves from."
                );
            }
            $lines[] = [
                'description' => $line->description,
                'quantity' => (int) $qty->toFloat(),
                'unit_price' => (string) ($line->unit_price_fx ?? $line->unit_price_sgd),
                'product_id' => $line->product_id,
                'stock_item_id' => $stockItem?->id,
                'warehouse_id' => $stockItem ? $warehouseId : null,
                'unit_of_measure' => $line->unit_of_measure,
                'unit_cost_sgd' => $line->cost_sgd !== null ? (string) $line->cost_sgd : null,
            ];
        }

        // In the quotation's currency, at the Currency Rate Table's rate on
        // the invoice date (else the quotation's own rate).
        $code = $quotation->currencyCode();
        $invoiceRate = Currency::rateOn($quotation->company_id, $code, Carbon::today()) ?? $quotation->rate();
        try {
            $invoice = BillingService::issueSalesInvoice(
                companyId: $quotation->company_id,
                customerId: $quotation->customer_id,
                lines: $lines,
                actorUserId: $actorUserId,
                description: "Quotation {$quotation->quotation_number}",
                currency: $code,
                rate: $invoiceRate,
            );
        } catch (InventoryRuleViolation|BillingRuleViolation $e) {
            throw new QuotationRuleViolation("Not accepted: {$e->getMessage()}");
        }
        if ($quotation->prospect_id !== null) {
            $invoice->prospect_id = $quotation->prospect_id;
            $invoice->save();
        }
        $quotation->converted_invoice_id = $invoice->id;

        return sprintf('Sales Invoice %s issued (SGD %s before GST).', $invoice->invoice_number, Money::of($invoice->amount_sgd)->toString());
    }

    /** PHP equivalent of Python's f"{value:g}" formatting used in the accept-message text. */
    private static function formatG(float $value): string
    {
        return sprintf('%g', $value);
    }
}
