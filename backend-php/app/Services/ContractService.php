<?php

namespace App\Services;

use App\Exceptions\ContractRuleViolation;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\ExpiredHoursRecord;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service Contracts business logic -- the single place that enforces
 * SRV-001, SRV-002, SRV-005, SRV-010, SRV-012, SRV-014, SRV-016,
 * SRV-018 (see docs/business-requirements.md for the confirmed rule
 * text). Mirrors backend/app/services/contracts.py exactly, including
 * its module docstring's architectural note: per
 * docs/system-architecture.md, "only Service Contracts logic decides
 * how contract hours are deducted" -- this is that single place.
 */
class ContractService
{
    /**
     * @param  array<string>|null  $productIds
     */
    public static function createContract(
        string $companyId,
        string $customerId,
        float $contractedHours,
        float $contractValueSgd,
        string $startDate,
        string $actorUserId,
        string $contractKind = Contract::KIND_SERVICE_SUPPORT,
        int $termMonths = Contract::STANDARD_CONTRACT_MONTHS,
        ?string $renewedFromContractId = null,
        ?float $hourlyRateSgd = null,
        ?string $salesStaffId = null,
        ?array $productIds = null,
    ): Contract {
        if ($contractKind === Contract::KIND_SERVICE_SUPPORT) {
            // SRV-002 / SRV-012: 10-hour hard minimum, no override mechanism.
            if ($contractedHours < Contract::MINIMUM_CONTRACTED_HOURS) {
                throw new ContractRuleViolation(sprintf(
                    'Contracted hours must be at least %d (SRV-002). There is no override mechanism (SRV-012).',
                    Contract::MINIMUM_CONTRACTED_HOURS,
                ));
            }
        } else {
            // ANNUAL (term-only) and AD_HOC: no hours at all -- any
            // contractedHours passed in is ignored rather than
            // silently accepted, so a caller can't end up with a
            // half-hourly annual/ad-hoc contract by mistake.
            $contractedHours = 0;
        }

        if ($contractKind === Contract::KIND_AD_HOC) {
            // AD_HOC: no upfront value -- work is billed as it
            // happens, off the reference rate below.
            $contractValueSgd = 0;
            if ($hourlyRateSgd === null || $hourlyRateSgd <= 0) {
                throw new ContractRuleViolation('An Ad Hoc Rate contract needs a reference hourly rate greater than zero.');
            }
        } else {
            $hourlyRateSgd = null;
        }

        // SRV-001: standard duration is 12 months by default, tracked
        // start/end date. termMonths lets an ANNUAL contract's term
        // differ if ever needed, without changing SERVICE_SUPPORT contracts.
        $endDate = Carbon::parse($startDate)->addMonthsNoOverflow($termMonths);

        $contract = Contract::create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'contract_number' => Numbering::next($companyId, 'contract'),
            'status' => Contract::STATUS_DRAFT,
            'contract_kind' => $contractKind,
            'contracted_minutes' => (int) ($contractedHours * 60),
            'consumed_minutes' => 0,
            'contract_value_sgd' => $contractValueSgd,
            'hourly_rate_sgd' => $hourlyRateSgd,
            'sales_staff_id' => $salesStaffId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'renewed_from_contract_id' => $renewedFromContractId,
        ]);

        foreach ($productIds ?? [] as $productId) {
            $product = Product::find($productId);
            if ($product === null || $product->company_id !== $companyId) {
                throw new ContractRuleViolation('Unknown product in product coverage.');
            }
            ContractProduct::create(['contract_id' => $contract->id, 'product_id' => $productId]);
        }

        Audit::record(
            'contract', $contract->id, 'created', $actorUserId,
            details: "kind={$contractKind}, contracted_hours={$contractedHours}, value_sgd={$contractValueSgd}",
        );

        return $contract;
    }

    public static function activateContract(Contract $contract, string $actorUserId): Contract
    {
        if ($contract->status !== Contract::STATUS_DRAFT) {
            throw new ContractRuleViolation('Only a Draft contract can be activated (SRV-001).');
        }

        $contract->status = Contract::STATUS_ACTIVE;
        $contract->activated_at = Carbon::now();
        $contract->save();

        Audit::record('contract', $contract->id, 'activated', $actorUserId);

        return $contract;
    }

    /**
     * Reduce the contract's remaining balance. Caller is responsible
     * for ensuring $minutes does not exceed the remaining balance --
     * SRV-004 requires the balance to never go negative. Not yet
     * called from anywhere in this backend (Service Records, its only
     * caller, isn't converted -- see docs/php-conversion-plan.md), but
     * ported now so the enforcement point exists ahead of that.
     */
    public static function deductMinutes(Contract $contract, int $minutes, string $actorUserId): void
    {
        if ($minutes > $contract->remainingMinutes()) {
            throw new ContractRuleViolation("Cannot deduct more than the contract's remaining balance (SRV-004).");
        }
        $contract->consumed_minutes += $minutes;

        // SRV-001: contract becomes "Exceeded" once fully consumed,
        // even though still within its 12-month term.
        if ($contract->remainingMinutes() === 0 && $contract->status === Contract::STATUS_ACTIVE) {
            $contract->status = Contract::STATUS_EXCEEDED;
            Audit::record('contract', $contract->id, 'status_changed_to_exceeded', $actorUserId);
        }
        $contract->save();
    }

    /** SRV-014: the pre-expiry accounting check window starts 30 days before expiry. */
    public static function needsPreExpiryCheck(Contract $contract, ?string $asOf = null): bool
    {
        $asOf = $asOf ? Carbon::parse($asOf) : Carbon::today();
        $daysUntilEnd = (int) $asOf->diffInDays($contract->end_date, false);

        return in_array($contract->status, [Contract::STATUS_ACTIVE, Contract::STATUS_EXCEEDED], true)
            && $daysUntilEnd >= 0 && $daysUntilEnd <= Contract::PRE_EXPIRY_CHECK_LEAD_DAYS;
    }

    /** SRV-005: forfeit all unused hours completely at expiry -- no roll-over, no credit, no transfer. Recorded, not deleted. */
    public static function expireContract(Contract $contract, string $actorUserId): ?ExpiredHoursRecord
    {
        if (! in_array($contract->status, [Contract::STATUS_ACTIVE, Contract::STATUS_EXCEEDED], true)) {
            throw new ContractRuleViolation('Only an Active or Exceeded contract can expire.');
        }

        $remaining = $contract->remainingMinutes();
        $record = null;
        if ($remaining > 0) {
            $record = ExpiredHoursRecord::create(['contract_id' => $contract->id, 'expired_minutes' => $remaining]);
        }

        $contract->status = Contract::STATUS_EXPIRED;
        $contract->save();

        Audit::record('contract', $contract->id, 'expired', $actorUserId, details: "forfeited_minutes={$remaining}");

        return $record;
    }

    /**
     * SRV-016: a renewal within 2 weeks of expiry is backdated (no
     * coverage gap). Beyond that, SRV-018 applies: no fixed rule --
     * handled case-by-case by Nico, Cherish, or Dennis. This only
     * reports eligibility; it never auto-decides the SRV-018 case.
     *
     * @return array{eligible_for_backdating: bool, days_since_expiry: int}
     */
    public static function checkRenewalEligibility(Contract $priorContract, ?string $renewalDate = null): array
    {
        $renewalDate = $renewalDate ? Carbon::parse($renewalDate) : Carbon::today();
        $daysSinceExpiry = (int) Carbon::parse($priorContract->end_date)->diffInDays($renewalDate, false);

        return [
            'eligible_for_backdating' => $daysSinceExpiry <= Contract::RENEWAL_BACKDATING_WINDOW_DAYS,
            'days_since_expiry' => $daysSinceExpiry,
        ];
    }

    /**
     * SRV-010: renewal creates a NEW contract record referencing the
     * prior one, with its own fresh hour allocation (it never inherits
     * the prior balance). SRV-016 governs whether it is backdated
     * seamlessly; beyond that window (SRV-018) the caller must supply
     * $forceStartDate explicitly, since there is no automatic rule for
     * that case -- it is a case-by-case human decision.
     */
    public static function renewContract(
        Contract $priorContract,
        float $contractedHours,
        float $contractValueSgd,
        string $actorUserId,
        ?string $renewalDate = null,
        ?string $forceStartDate = null,
        ?float $hourlyRateSgd = null,
    ): Contract {
        $eligibility = self::checkRenewalEligibility($priorContract, $renewalDate);

        if ($forceStartDate !== null) {
            $startDate = $forceStartDate;
        } elseif ($eligibility['eligible_for_backdating']) {
            $startDate = $priorContract->end_date; // seamless, no coverage gap
        } else {
            throw new ContractRuleViolation(
                'Renewal is beyond the SRV-016 2-week backdating window '
                ."({$eligibility['days_since_expiry']} days since expiry). Per SRV-018 "
                .'this must be a case-by-case decision by Nico, Cherish, or Dennis -- '
                .'pass force_start_date explicitly to proceed.'
            );
        }

        if (! in_array($priorContract->status, [Contract::STATUS_EXPIRED, Contract::STATUS_EXCEEDED, Contract::STATUS_ACTIVE], true)) {
            throw new ContractRuleViolation('Prior contract must be Active, Exceeded, or Expired to renew.');
        }

        // Product coverage and the sales staff owner carry forward from
        // the prior contract by default -- a renewal is the same
        // commercial relationship continuing, not a fresh setup.
        $newContract = self::createContract(
            companyId: $priorContract->company_id,
            customerId: $priorContract->customer_id,
            contractedHours: $contractedHours,
            contractValueSgd: $contractValueSgd,
            startDate: (string) $startDate,
            actorUserId: $actorUserId,
            contractKind: $priorContract->contract_kind,
            renewedFromContractId: $priorContract->id,
            hourlyRateSgd: $hourlyRateSgd ?? ($priorContract->hourly_rate_sgd !== null ? (float) $priorContract->hourly_rate_sgd : null),
            salesStaffId: $priorContract->sales_staff_id,
            productIds: $priorContract->products->pluck('product_id')->all(),
        );

        if ($priorContract->status !== Contract::STATUS_EXPIRED) {
            self::expireContract($priorContract, $actorUserId);
        }

        $priorContract->status = Contract::STATUS_RENEWED;
        $priorContract->save();

        Audit::record(
            'contract', $newContract->id, 'renewed_from', $actorUserId,
            details: "prior_contract_id={$priorContract->id}, backdated=".($eligibility['eligible_for_backdating'] ? 'true' : 'false'),
        );

        return $newContract;
    }

    /**
     * NEW FEATURE (not a Python->PHP conversion -- see
     * docs/backlog.md / docs/planned-work.md): "Service Contract - To
     * be able to link to Sales Quotation upon Renewal or Expired".
     * PRAGMATIC DEFAULT / KNOWN GAP -- see App\Models\Contract's
     * docblock: this stores a free-text reference only, settable once
     * the contract has actually transitioned to Renewed or Expired
     * (matching the feature request's own wording), not at any other
     * point in its lifecycle. Always audit-logged.
     */
    public static function setQuotationReference(Contract $contract, string $quotationReference, string $actorUserId): Contract
    {
        if (! in_array($contract->status, [Contract::STATUS_RENEWED, Contract::STATUS_EXPIRED], true)) {
            throw new ContractRuleViolation(
                'A Sales Quotation reference can only be recorded once the contract has transitioned to '
                .'Renewed or Expired.'
            );
        }

        $old = $contract->quotation_reference;
        $contract->quotation_reference = trim($quotationReference);
        $contract->quotation_reference_set_at = Carbon::now();
        $contract->quotation_reference_set_by = $actorUserId;
        $contract->save();

        Audit::record(
            'contract', $contract->id, 'quotation_reference_set', $actorUserId,
            oldValue: ['quotation_reference' => $old],
            newValue: ['quotation_reference' => $contract->quotation_reference],
        );

        return $contract;
    }

    // ── Contract <-> Sales Quotation (SALES-006, 2026-09-15) ────────

    /** The renewal quotation raised from this contract that is still in play, if any. */
    public static function openRenewalQuotation(Contract $contract): ?Quotation
    {
        return Quotation::where('renews_contract_id', $contract->id)
            ->whereIn('status', Quotation::OPEN_STATUSES)
            ->orderByDesc('quotation_date')->orderByDesc('quotation_number')
            ->first();
    }

    /**
     * "Hours finishing": a Service Support contract with
     * RENEWAL_HOURS_FINISHING_FRACTION or less of its hours left.
     */
    public static function hoursFinishing(Contract $contract): bool
    {
        if ($contract->contract_kind !== Contract::KIND_SERVICE_SUPPORT || $contract->contracted_minutes <= 0) {
            return false;
        }

        return $contract->remainingMinutes() <= (int) floor($contract->contracted_minutes * Contract::RENEWAL_HOURS_FINISHING_FRACTION);
    }

    /**
     * Why this contract is coming due -- the reason its renewal
     * quotation can be raised -- or null when it is not: expired,
     * exceeded, within SRV-014's 30-day pre-expiry window, or hours
     * finishing (Dennis, 2026-09-15: "when it's going to due / hrs
     * finishing ... date going to due").
     */
    public static function renewalDueReason(Contract $contract): ?string
    {
        if ($contract->status === Contract::STATUS_EXPIRED) {
            return 'expired on '.Carbon::parse($contract->end_date)->toDateString();
        }
        if ($contract->status === Contract::STATUS_EXCEEDED) {
            return 'hours exceeded';
        }
        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return null;
        }
        $reasons = [];
        if (self::needsPreExpiryCheck($contract)) {
            $days = (int) Carbon::today()->diffInDays($contract->end_date, false);
            $reasons[] = 'expires on '.Carbon::parse($contract->end_date)->toDateString()." ({$days} days)";
        }
        if (self::hoursFinishing($contract)) {
            $reasons[] = sprintf('%.1f of %.1f hours left', $contract->remainingMinutes() / 60, $contract->contracted_minutes / 60);
        }

        return $reasons ? implode('; ', $reasons) : null;
    }

    /**
     * Why a renewal quotation cannot be raised right now, or null when
     * it can: the contract is Ad Hoc (no upfront value to quote),
     * already renewed, not coming due yet (see renewalDueReason), or
     * already has an open renewal quotation.
     */
    public static function renewalQuotationBlocker(Contract $contract): ?string
    {
        if ($contract->contract_kind === Contract::KIND_AD_HOC) {
            return 'An Ad Hoc Rate contract has no upfront value to quote -- renew it directly.';
        }
        if ($contract->status === Contract::STATUS_RENEWED) {
            return "{$contract->contract_number} has already been renewed.";
        }
        if (self::renewalDueReason($contract) === null) {
            return sprintf(
                '%s is not coming due yet: more than %d days to expiry (SRV-014) and %.1f of %.1f hours left, so a new quotation cannot be raised for it.',
                $contract->contract_number,
                Contract::PRE_EXPIRY_CHECK_LEAD_DAYS,
                $contract->remainingMinutes() / 60,
                $contract->contracted_minutes / 60,
            );
        }
        if ($open = self::openRenewalQuotation($contract)) {
            return "{$contract->contract_number} already has an open renewal quotation, {$open->quotation_number} ({$open->status}).";
        }

        return null;
    }

    /**
     * Raise a draft Sales Quotation carrying this contract's current
     * terms as its line, marked as renewing it. From there it goes
     * through the ordinary approval and sending (SALES-008); accepting
     * it renews the contract -- see QuotationService::acceptQuotation.
     */
    public static function createRenewalQuotation(Contract $contract, string $actorUserId): Quotation
    {
        if ($blocker = self::renewalQuotationBlocker($contract)) {
            throw new ContractRuleViolation($blocker);
        }

        $hours = $contract->contracted_minutes / 60;
        $value = Money::of($contract->contract_value_sgd);

        if ($contract->contract_kind === Contract::KIND_SERVICE_SUPPORT) {
            // Hours at the contract's blended rate, so accepting it
            // yields the same hours and value the contract has now;
            // Sales reprice the line before sending if terms change.
            $line = [
                'description' => "Renewal of {$contract->contract_number}: support hours",
                'unit_of_measure' => 'Hours',
                'quantity' => Money::of($hours)->toString(),
                'unit_price_sgd' => $value->dividedBy($hours)->quantize()->toString(),
            ];
        } else {
            $line = [
                'description' => "Renewal of {$contract->contract_number}: annual contract",
                'unit_of_measure' => null,
                'quantity' => Money::of(1)->toString(),
                'unit_price_sgd' => $value->quantize()->toString(),
            ];
        }

        return DB::transaction(function () use ($contract, $actorUserId, $line) {
            $quotation = Quotation::create([
                'company_id' => $contract->company_id,
                'quotation_number' => Numbering::next($contract->company_id, 'quotation'),
                'customer_id' => $contract->customer_id,
                'quotation_date' => Carbon::today()->toDateString(),
                'notes' => "Renewal of contract {$contract->contract_number} (expires "
                    .Carbon::parse($contract->end_date)->toDateString().').',
                'created_by_user_id' => $actorUserId,
                'renews_contract_id' => $contract->id,
            ]);
            QuotationLine::create($line + [
                'quotation_id' => $quotation->id,
                'line_total_sgd' => Money::of($line['quantity'])->multipliedByMoney(Money::of($line['unit_price_sgd']))->quantize()->toString(),
            ]);
            $quotation->load('lines');
            QuotationService::recomputeTotals($quotation);
            $quotation->save();

            Audit::record('quotation', $quotation->id, 'created', $actorUserId,
                details: "{$quotation->quotation_number}: renewal of {$contract->contract_number}",
                newValue: ['renews_contract_id' => $contract->id, 'total_amount_sgd' => (float) $quotation->total_amount_sgd]);
            Audit::record('contract', $contract->id, 'renewal_quotation_raised', $actorUserId,
                details: "{$quotation->quotation_number}",
                newValue: ['quotation_id' => $quotation->id]);

            return $quotation;
        });
    }

    /** Link a quotation to this contract by hand. Same customer, or it is not this contract's quotation. */
    public static function linkQuotation(Contract $contract, Quotation $quotation, string $actorUserId): Contract
    {
        if ($quotation->customer_id !== $contract->customer_id) {
            throw new ContractRuleViolation(
                "{$quotation->quotation_number} belongs to a different Company/Individual than {$contract->contract_number}."
            );
        }
        $old = $contract->quotation_id;
        $contract->quotation_id = $quotation->id;
        $contract->save();

        Audit::record('contract', $contract->id, 'quotation_linked', $actorUserId,
            details: $quotation->quotation_number,
            oldValue: ['quotation_id' => $old], newValue: ['quotation_id' => $quotation->id]);

        return $contract;
    }

    /**
     * NEW FEATURE (not a Python->PHP conversion -- see
     * docs/backlog.md / docs/planned-work.md): "Contract due for
     * renewal Listing" -- reuses needsPreExpiryCheck()'s SRV-014
     * 30-day pre-expiry window exactly, so this listing and the
     * contract detail page's own "needs a pre-expiry check" flag can
     * never disagree.
     *
     * @return Collection<int, Contract>
     */
    public static function dueForRenewal(string|array $companyId, ?string $asOf = null)
    {
        return Contract::with('products.product')
            ->whereIn('company_id', (array) $companyId)
            ->whereIn('status', [Contract::STATUS_ACTIVE, Contract::STATUS_EXCEEDED])
            ->get()
            ->filter(fn (Contract $c) => self::needsPreExpiryCheck($c, $asOf))
            ->sortBy('end_date')
            ->values();
    }

    /**
     * NEW FEATURE (not a Python->PHP conversion -- see
     * docs/backlog.md / docs/planned-work.md): "Contract Expiry
     * Listing" -- contracts expiring within a date range, OR already
     * expired (the two conditions are OR'd -- an already-expired
     * contract should always show here, whether or not its end_date
     * happens to fall inside the given window). PRAGMATIC DEFAULT: when
     * neither $from nor $to is given, defaults to a 90-day-forward
     * window from today so the report stays bounded rather than
     * dumping every contract ever created -- documented here per
     * CLAUDE.md, not a confirmed rule.
     *
     * @return Collection<int, Contract>
     */
    public static function expiryListing(string|array $companyId, ?string $from = null, ?string $to = null)
    {
        if ($from === null && $to === null) {
            $from = Carbon::today()->toDateString();
            $to = Carbon::today()->addDays(90)->toDateString();
        }

        return Contract::with('products.product')
            ->whereIn('company_id', (array) $companyId)
            ->where(function ($q) use ($from, $to) {
                $q->where('status', Contract::STATUS_EXPIRED);
                $q->orWhere(function ($q2) use ($from, $to) {
                    if ($from !== null) {
                        $q2->where('end_date', '>=', $from);
                    }
                    if ($to !== null) {
                        $q2->where('end_date', '<=', $to);
                    }
                });
            })
            ->orderBy('end_date')
            ->get();
    }
}
