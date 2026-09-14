<?php

namespace App\Services;

use App\Exceptions\ContractRuleViolation;
use App\Models\Contract;
use App\Models\ContractProduct;
use App\Models\ExpiredHoursRecord;
use App\Models\Product;
use Illuminate\Support\Carbon;

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
        $contract->activated_at = Carbon::now('UTC');
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
}
