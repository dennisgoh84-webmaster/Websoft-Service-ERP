<?php

namespace App\Services;

use App\Exceptions\ContractRuleViolation;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Excess Usage review -- SRV-004 (Nico's, or Cherish's, decision,
 * always recorded with a reason), SRV-013 (treatment categories), and
 * SRV-006/SRV-008 (a billable decision must proceed to invoicing, at
 * the contract's blended rate). Mirrors
 * backend/app/services/excess_usage.py's decide_excess_usage exactly,
 * including the BILLABLE -> App\Services\BillingService::
 * issueExcessUsageInvoice() call now that Billing is converted -- see
 * BillingService's class docblock for what that invoice still doesn't
 * do (GL posting).
 */
class ExcessUsageService
{
    // Same set as ServiceRecordService::APPROVER_ROLES (Python imports
    // EXCESS_REVIEWER_ROLES from service_records.py, i.e. the same
    // constant reused under a different name).
    public const REVIEWER_ROLES = ServiceRecordService::APPROVER_ROLES;

    public static function decideExcessUsage(
        ExcessUsageRecord $record,
        Contract $contract,
        string $treatment,
        string $reason,
        User $reviewer,
    ): ?Invoice {
        if (! in_array($reviewer->role, self::REVIEWER_ROLES, true)) {
            throw new ContractRuleViolation(
                'Only Nico (service_lead), Cherish as backup (sales_manager), or '.
                'Dennis (owner) may decide excess usage treatment (SRV-004/SRV-011).'
            );
        }
        if ($record->isDecided()) {
            throw new ContractRuleViolation('This excess usage has already been decided.');
        }
        if (trim($reason) === '') {
            // SRV-004: "The decision and reason must be auditable" -- a
            // reason is not optional.
            throw new ContractRuleViolation('A reason is required for every excess usage decision (SRV-004).');
        }

        $record->treatment = $treatment;
        $record->reason = trim($reason);
        $record->decided_by_user_id = $reviewer->id;
        $record->decided_at = Carbon::now();
        $record->save();

        Audit::record(
            entityType: 'excess_usage_record',
            entityId: $record->id,
            action: 'decided',
            actorUserId: $reviewer->id,
            reason: $reason,
            details: "treatment={$treatment}",
        );

        if ($treatment === ExcessUsageRecord::TREATMENT_BILLABLE) {
            // SRV-006/SRV-008: billable excess must proceed to
            // invoicing, at the contract's blended rate, no customer
            // pre-approval needed.
            return BillingService::issueExcessUsageInvoice($record, $contract, $reviewer->id);
        }

        return null;
    }
}
