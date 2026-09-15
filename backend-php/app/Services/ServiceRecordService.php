<?php

namespace App\Services;

use App\Exceptions\ContractRuleViolation;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Service Records business logic (formerly "Timesheets"): SRV-007
 * rounding, SRV-015 missing-record flagging, and the SRV-003/SRV-004
 * contract-hour validation that decides Contract Deduction vs. Excess
 * Review on approval. Mirrors backend/app/services/service_records.py
 * exactly -- see that file's module docstring.
 */
class ServiceRecordService
{
    // SRV-019 (Dennis, 2026-09-15, settling open item 9.1): "only Nico
    // and Cherish can approve the deduct hrs" -- Nico is the Service
    // Lead role, Cherish the Sales Manager role, as everywhere else in
    // this system. The owner is deliberately NOT in this list any more
    // (the earlier pragmatic default let Dennis approve too). Within a
    // week: ServiceRecord::APPROVAL_DEADLINE_DAYS.
    public const APPROVER_ROLES = [User::ROLE_SERVICE_LEAD, User::ROLE_SALES_MANAGER];

    // Confirmed 2026-09-11: Urgent x1.5, After-Office-Hours/Weekend/
    // Holiday x2.0. When a record is both, the higher one wins rather
    // than the two multiplying together (a default, not an explicitly
    // confirmed rule).
    public const URGENT_MULTIPLIER = 1.5;

    public const AFTER_HOURS_MULTIPLIER = 2.0;

    /**
     * Only ever a *suggestion* prefilled on the Service Record Approval
     * form -- the approver can key in any value regardless.
     */
    public static function suggestedDeductionMinutes(int $roundedMinutes, bool $isUrgent, bool $isAfterHours): int
    {
        $multiplier = 1.0;
        if ($isUrgent) {
            $multiplier = max($multiplier, self::URGENT_MULTIPLIER);
        }
        if ($isAfterHours) {
            $multiplier = max($multiplier, self::AFTER_HOURS_MULTIPLIER);
        }

        return (int) ceil($roundedMinutes * $multiplier);
    }

    public static function submitServiceRecord(
        string $jobOrderId,
        string $employeeUserId,
        string $workDate,
        int $rawMinutes,
        string $actorUserId,
        string $completionStatus = ServiceRecord::UNCOMPLETED,
        bool $isAfterHours = false,
        ?string $workDescription = null,
    ): ServiceRecord {
        $jobOrder = JobOrder::find($jobOrderId);
        if ($jobOrder === null) {
            throw new ContractRuleViolation('Job order not found.');
        }
        if (in_array($jobOrder->status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true)) {
            throw new ContractRuleViolation("Job order is {$jobOrder->status}; reopen it first.");
        }

        $roundedMinutes = ServiceRecord::roundUpToNearest($rawMinutes);

        $record = ServiceRecord::create([
            // Multi-company: a service record belongs to the same
            // company as the job order the work was logged against.
            'company_id' => $jobOrder->company_id,
            'service_record_number' => Numbering::next($jobOrder->company_id, 'service_record'),
            'job_order_id' => $jobOrderId,
            'employee_user_id' => $employeeUserId,
            'work_date' => $workDate,
            'raw_minutes' => $rawMinutes,
            'rounded_minutes' => $roundedMinutes, // SRV-007
            'status' => ServiceRecord::STATUS_SUBMITTED,
            'outcome' => ServiceRecord::OUTCOME_PENDING,
            // Set here rather than left to the column's DB default so the
            // SRV-019 approval deadline runs off the application clock.
            'submitted_at' => Carbon::now('UTC'),
            'completion_status' => $completionStatus,
            'is_after_hours' => $isAfterHours,
            'work_description' => $workDescription,
        ]);

        Audit::record(
            'service_record', $record->id, 'submitted', $actorUserId,
            details: "raw_minutes={$rawMinutes}, rounded_minutes={$roundedMinutes}, "
                ."completion_status={$completionStatus}, is_after_hours=".($isAfterHours ? 'true' : 'false'),
        );

        return $record;
    }

    /**
     * Confirmed 2026-09-11: a Job Order auto-closes when its most
     * recently submitted Service Record is Approved AND marked
     * Completed ('C', not Uncompleted 'U') -- only the latest record
     * matters, so earlier "more visits needed" (U) records don't block
     * closing once the final visit is done. Returns true if this call
     * closed it.
     */
    public static function maybeAutoCloseJobOrder(JobOrder $jobOrder, User $actor): bool
    {
        if (! in_array($jobOrder->status, [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED], true)) {
            return false;
        }

        $latest = ServiceRecord::where('job_order_id', $jobOrder->id)
            ->orderByDesc('work_date')
            ->orderByDesc('submitted_at')
            ->first();

        if ($latest === null || $latest->status !== ServiceRecord::STATUS_APPROVED || $latest->completion_status !== ServiceRecord::COMPLETED) {
            return false;
        }

        $oldStatus = $jobOrder->status;
        $jobOrder->status = JobOrder::STATUS_CLOSED;
        $jobOrder->closed_at = Carbon::now('UTC');
        $jobOrder->save();

        Audit::record(
            'job_order', $jobOrder->id, 'auto_closed', $actor->id,
            details: "triggered by service_record={$latest->id} (last record, Approved + Completed)",
            oldValue: ['status' => $oldStatus], newValue: ['status' => $jobOrder->status],
        );

        return true;
    }

    public static function approveServiceRecord(
        ServiceRecord $record,
        JobOrder $jobOrder,
        User $approver,
        int $deductedMinutes,
    ): ?ExcessUsageRecord {
        if (! in_array($approver->role, self::APPROVER_ROLES, true)) {
            throw new ContractRuleViolation(
                'Only Nico (service_lead) or Cherish (sales_manager) may approve a Service '
                .'Record and key in the deducted hours (SRV-019).'
            );
        }
        if ($record->status !== ServiceRecord::STATUS_SUBMITTED) {
            throw new ContractRuleViolation('Only a submitted Service Record can be approved.');
        }
        if ($deductedMinutes <= 0) {
            throw new ContractRuleViolation('Deducted minutes must be greater than zero.');
        }
        if ($jobOrder->contract_id === null) {
            throw new ContractRuleViolation(
                'This job order has no linked Service Contract; contract-hour validation '
                .'requires one for this build\'s scope.'
            );
        }

        $record->status = ServiceRecord::STATUS_APPROVED;
        $record->approved_at = Carbon::now('UTC');
        $record->approved_by_user_id = $approver->id;
        $record->deducted_minutes = $deductedMinutes;

        $contract = Contract::findOrFail($jobOrder->contract_id);
        $excessRecord = null;

        // SRV-020: the Job Order's context decides what this time is.
        // BILLABLE and NON_BILLABLE never touch the contract's hour
        // pool; only CONTRACT falls through to the SRV-003/004 check.
        $classification = $jobOrder->billing_classification ?? JobOrder::BILLING_CONTRACT;
        if ($classification !== JobOrder::BILLING_CONTRACT) {
            $record->outcome = $classification === JobOrder::BILLING_BILLABLE
                ? ServiceRecord::OUTCOME_BILLABLE
                : ServiceRecord::OUTCOME_NON_BILLABLE;
            $record->save();
            Audit::record(
                'service_record', $record->id, 'approved', $approver->id,
                details: "outcome={$record->outcome}, deducted_minutes={$deductedMinutes} (job order classified {$classification}, SRV-020)",
            );
            self::maybeAutoCloseJobOrder($jobOrder, $approver);

            return null;
        }

        if (in_array($contract->contract_kind, [Contract::KIND_ANNUAL, Contract::KIND_AD_HOC], true)) {
            // Confirmed 2026-09-10 (ANNUAL) / 2026-09-11 (AD_HOC):
            // neither has an hour pool, so there is nothing to deduct
            // or exceed -- the work is simply covered under the
            // contract's term (ANNUAL) or billed manually off its
            // reference rate (AD_HOC).
            $record->outcome = ServiceRecord::OUTCOME_NOT_HOUR_METERED;
            $record->save();
            Audit::record(
                'service_record', $record->id, 'approved', $approver->id,
                details: "outcome={$record->outcome}, deducted_minutes={$deductedMinutes} (not hour-metered)",
            );
            self::maybeAutoCloseJobOrder($jobOrder, $approver);

            return null;
        }

        $remaining = $contract->remainingMinutes();

        if ($remaining >= $deductedMinutes) {
            // SRV-003: hours remain -- straightforward contract deduction.
            ContractService::deductMinutes($contract, $deductedMinutes, $approver->id);
            $record->outcome = ServiceRecord::OUTCOME_CONTRACT_DEDUCTION;
        } else {
            // SRV-003: no grace period. Deduct whatever balance remains
            // (may be zero) and the rest becomes Excess Usage
            // immediately -- never a negative balance (SRV-004).
            if ($remaining > 0) {
                ContractService::deductMinutes($contract, $remaining, $approver->id);
            }
            $excessMinutes = $deductedMinutes - $remaining;
            $excessRecord = ExcessUsageRecord::create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'service_record_id' => $record->id,
                'excess_minutes' => $excessMinutes,
            ]);
            $record->outcome = ServiceRecord::OUTCOME_EXCESS_USAGE;

            Audit::record(
                'excess_usage_record', $excessRecord->id, 'created', $approver->id,
                details: "excess_minutes={$excessMinutes}, awaiting_review=true",
            );
        }

        $record->save();

        Audit::record(
            'service_record', $record->id, 'approved', $approver->id,
            details: "outcome={$record->outcome}, deducted_minutes={$deductedMinutes}",
        );

        self::maybeAutoCloseJobOrder($jobOrder, $approver);

        return $excessRecord;
    }
}
