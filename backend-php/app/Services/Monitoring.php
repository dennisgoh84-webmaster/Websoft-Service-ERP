<?php

namespace App\Services;

use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Support monitoring: per-staff Job Order workload and contract-hours
 * throughput, so a supervisor can see who is overloaded at a glance.
 * Mirrors backend/app/services/monitoring.py 1:1.
 *
 * Confirmed 2026-09-10 (reference: a legacy "Monitoring Support"
 * screen).
 *
 * OPEN ITEM, deliberately not guessed: "due soon" has no confirmed lead
 * time. DUE_SOON_LEAD_DAYS below is a pragmatic default (2 days), not a
 * confirmed business rule -- carried across from Python unchanged,
 * including its reasoning (SRV-009 remains deferred). It only buckets a
 * Job Order that already has a manually-set due_date into "due soon"
 * vs. merely "overdue" vs. neither.
 *
 * KNOWN GAP: "Un-Test S/T" counts Software Task rows assigned to a
 * tester but not yet marked tested. The Software Tasks module is not
 * converted to backend-php yet, so there is no software_tasks table to
 * count -- every untested-task figure here is therefore 0 rather than a
 * real count, and the gap is recorded in docs/php-conversion-plan.md.
 * The shape of the response is unchanged, so the screen renders and the
 * column simply reads 0 until that module lands, at which point the
 * counting loop below is the only thing that needs filling in. Pinned
 * by a test so it cannot be mistaken for a real zero.
 */
class Monitoring
{
    /** Pragmatic default, not a confirmed SLA rule -- see class docblock. */
    public const DUE_SOON_LEAD_DAYS = 2;

    /** @return array<string, mixed> */
    public static function getSupportMonitoring(string $companyId, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
        $monthStart = $asOf->copy()->startOfMonth();

        $jobOrders = JobOrder::where('company_id', $companyId)->get();

        // Python scopes service records through the JOB ORDER's company,
        // not the record's own column -- mirrored rather than shortcut.
        $serviceRecords = ServiceRecord::whereHas(
            'jobOrder',
            fn ($q) => $q->where('company_id', $companyId)
        )->get();

        $staff = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('role', '!=', User::ROLE_OWNER)
            ->orderBy('full_name')
            ->get();

        $byStaff = [];
        foreach ($staff as $u) {
            $byStaff[$u->id] = self::blankRow($u->id, $u->full_name, $u->photo);
        }
        // Python uses uuid.UUID(int=0) for the synthetic "Un-Assigned" row.
        $unassigned = self::blankRow('00000000-0000-0000-0000-000000000000', 'Un-Assigned', null);

        $summary = [
            'total_job_orders' => 0,
            'total_open_job_orders' => 0,
            'total_overdue_job_orders' => 0,
            'unassigned_job_orders' => 0,
            'total_pending_service_records' => 0,
            'total_untested_software_tasks' => 0,
        ];

        foreach ($jobOrders as $jo) {
            $summary['total_job_orders']++;
            $isOpen = in_array($jo->status, [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED], true);
            if ($isOpen) {
                $summary['total_open_job_orders']++;
            }

            // A job order assigned to someone NOT in the staff map -- the
            // owner, or a deactivated user -- lands in nobody's row, but
            // still counts in the totals. Python behaves the same way;
            // pinned by a test.
            $key = null;
            if ($jo->assigned_to_user_id !== null && isset($byStaff[$jo->assigned_to_user_id])) {
                $key = $jo->assigned_to_user_id;
            }
            $row = $key !== null ? $byStaff[$key] : null;
            if ($row === null && $jo->assigned_to_user_id === null) {
                $row = $unassigned;
                if ($isOpen) {
                    $summary['unassigned_job_orders']++;
                }
            }

            if ($row !== null && $isOpen) {
                $row['open_job_orders']++;
                if ($jo->due_date !== null) {
                    $daysLeft = $asOf->diffInDays($jo->due_date->copy()->startOfDay(), false);
                    if ($daysLeft < 0) {
                        $row['overdue_job_orders']++;
                        $summary['total_overdue_job_orders']++;
                    } elseif ($daysLeft <= self::DUE_SOON_LEAD_DAYS) {
                        $row['due_soon_job_orders']++;
                    }
                }
            }

            if ($row !== null) {
                if ($key !== null) {
                    $byStaff[$key] = $row;
                } else {
                    $unassigned = $row;
                }
            }
        }

        foreach ($serviceRecords as $sr) {
            if (! isset($byStaff[$sr->employee_user_id])) {
                continue;
            }
            $row = $byStaff[$sr->employee_user_id];

            if ($sr->status === ServiceRecord::STATUS_SUBMITTED) {
                $row['pending_service_records']++;
                $summary['total_pending_service_records']++;
            }

            if ($sr->status !== ServiceRecord::STATUS_APPROVED || $sr->approved_at === null) {
                $byStaff[$sr->employee_user_id] = $row;

                continue;
            }

            $approvedDate = $sr->approved_at->copy()->startOfDay();
            if ($approvedDate->lt($monthStart)) {
                $byStaff[$sr->employee_user_id] = $row;

                continue;
            }

            $row['cm_svc_records_month']++;
            $isToday = $approvedDate->isSameDay($asOf);
            if ($isToday) {
                $row['cm_svc_records_today']++;
            }
            if ($sr->outcome === ServiceRecord::OUTCOME_CONTRACT_DEDUCTION) {
                $hours = $sr->rounded_minutes / 60;
                $row['cm_svc_hours_month'] += $hours;
                if ($isToday) {
                    $row['cm_svc_hours_today'] += $hours;
                }
            }

            $byStaff[$sr->employee_user_id] = $row;
        }

        // KNOWN GAP (see class docblock): nothing to count until the
        // Software Tasks module is converted. Python's loop also
        // increments the summary total for EVERY untested task, whether
        // or not it has a tester assigned -- preserved for when this is
        // filled in.

        // Average daily contract hours deducted this month, per staff --
        // the denominator is the number of days elapsed SO FAR this
        // month, not the number of days actually worked, so it reads as
        // "how many hours a day is this person contributing on average"
        // rather than being inflated by only counting days they logged
        // something. Note Python applies this to the staff rows only,
        // never to the Un-Assigned row, which therefore stays 0.0.
        $daysElapsed = $monthStart->diffInDays($asOf) + 1;
        if ($daysElapsed > 0) {
            foreach ($byStaff as $id => $row) {
                $row['avg_daily_contract_hours'] = $row['cm_svc_hours_month'] / $daysElapsed;
                $byStaff[$id] = $row;
            }
        }

        return [
            'as_at' => $asOf->toDateString(),
            'summary' => $summary,
            'staff' => array_values($byStaff),
            'unassigned' => $unassigned,
        ];
    }

    /** @return array<string, mixed> */
    private static function blankRow(string $userId, string $fullName, ?string $photo): array
    {
        return [
            'user_id' => $userId,
            'full_name' => $fullName,
            // Confirmed 2026-09-11: shown as an avatar on the screen.
            'photo' => $photo,
            'open_job_orders' => 0,
            'overdue_job_orders' => 0,
            'due_soon_job_orders' => 0,
            'pending_service_records' => 0,
            'untested_software_tasks' => 0,
            'cm_svc_records_month' => 0,
            'cm_svc_records_today' => 0,
            'cm_svc_hours_month' => 0.0,
            'cm_svc_hours_today' => 0.0,
            'avg_daily_contract_hours' => 0.0,
        ];
    }
}
