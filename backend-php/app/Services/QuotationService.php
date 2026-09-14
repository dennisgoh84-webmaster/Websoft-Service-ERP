<?php

namespace App\Services;

use App\Exceptions\ContractRuleViolation;
use App\Models\Contract;
use App\Models\Quotation;
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
    public static function acceptQuotation(Quotation $quotation, string $actorUserId): string
    {
        $quotation->status = Quotation::STATUS_ACCEPTED;

        $hourlyLines = $quotation->lines->filter(fn ($line) => self::isHourly($line->unit_of_measure));
        $otherLines = $quotation->lines->reject(fn ($line) => self::isHourly($line->unit_of_measure));

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
            $quotation->converted_annual_contract_id = $annualContract->id;
            $messages[] = sprintf('Annual contract created (SGD %.2f, 12-month term).', $otherValue->toFloat());
        }

        $message = $messages ? 'Quotation accepted. '.implode(' ', $messages) : 'Quotation accepted.';

        Audit::record('quotation', $quotation->id, 'accepted', $actorUserId, details: $message);

        return $message;
    }

    /** PHP equivalent of Python's f"{value:g}" formatting used in the accept-message text. */
    private static function formatG(float $value): string
    {
        return sprintf('%g', $value);
    }
}
