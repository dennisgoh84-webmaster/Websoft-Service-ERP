<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * SRV-019's daily reminder (Dennis, 2026-09-26, decision 9.1 and #50:
 * "Flag + email the approvers", "Add a timer to the server", "8:45
 * am"). Each morning, for each company with any Service Record left
 * unapproved more than ServiceRecord::APPROVAL_DEADLINE_DAYS after it
 * was submitted, the approvers (ServiceRecordService::APPROVER_ROLES --
 * the Service Lead and the Sales Manager) get one email listing them.
 * Nothing is sent on a day with none overdue, and nothing is approved
 * or blocked -- SRV-019 stays a nudge.
 *
 * Sent through the system mailbox (Mailer::send), as staff email is.
 * With no mailbox configured the reminder is skipped and Event Logs
 * say so. Run by the scheduler (routes/console.php) at 08:45 SGT.
 */
class ServiceRecordReminder
{
    /** @return array<string, array{overdue: int, sent_to: list<string>, skipped: ?string}> per company id */
    public static function sendOverdueApprovals(): array
    {
        $cutoff = Carbon::now()->subDays(ServiceRecord::APPROVAL_DEADLINE_DAYS);
        $report = [];

        $companyIds = ServiceRecord::where('status', ServiceRecord::STATUS_SUBMITTED)
            ->where('submitted_at', '<', $cutoff)
            ->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $records = ServiceRecord::with(['jobOrder.customer', 'employee'])
                ->where('company_id', $companyId)
                ->where('status', ServiceRecord::STATUS_SUBMITTED)
                ->where('submitted_at', '<', $cutoff)
                ->orderBy('submitted_at')
                ->get();
            $approvers = User::where('company_id', $companyId)
                ->whereIn('role', ServiceRecordService::APPROVER_ROLES)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->get();

            $company = Company::find($companyId);
            $subject = sprintf('%d Service Record%s waiting over %d days for approval', $records->count(), $records->count() === 1 ? '' : 's', ServiceRecord::APPROVAL_DEADLINE_DAYS);
            $body = self::body($company?->name, $records);

            $sentTo = [];
            $skipped = null;
            if ($approvers->isEmpty()) {
                $skipped = 'no active Service Lead or Sales Manager with an email address';
            } elseif (! Mailer::isConfigured()) {
                $skipped = 'the system mailbox is not set up (Maintenance -> System Email)';
            } else {
                foreach ($approvers as $approver) {
                    try {
                        Mailer::send($approver->email, $subject, "Hello {$approver->full_name},\n\n".$body);
                        $sentTo[] = $approver->email;
                    } catch (MailerNotConfiguredException|MailerException $e) {
                        $skipped = $e->getMessage();
                    }
                }
            }

            Audit::record(
                entityType: 'service_record_reminder',
                entityId: $companyId,
                action: $sentTo ? 'overdue_approval_reminder_sent' : 'overdue_approval_reminder_not_sent',
                actorUserId: null,
                actorName: 'System (daily 08:45 reminder)',
                companyId: $companyId,
                details: $sentTo
                    ? sprintf('%s sent to %s', $subject, implode(', ', $sentTo))
                    : sprintf('%s -- not sent: %s', $subject, $skipped),
                newValue: ['service_record_ids' => $records->pluck('id')->all(), 'sent_to' => $sentTo],
            );
            $report[$companyId] = ['overdue' => $records->count(), 'sent_to' => $sentTo, 'skipped' => $sentTo ? null : $skipped];
        }

        return $report;
    }

    private static function body(?string $companyName, $records): string
    {
        $lines = [
            sprintf('These Service Records%s were submitted more than %d days ago and are still waiting for approval:',
                $companyName ? " for {$companyName}" : '', ServiceRecord::APPROVAL_DEADLINE_DAYS),
            '',
        ];
        foreach ($records as $r) {
            $days = (int) floor(Carbon::parse($r->submitted_at)->diffInDays(Carbon::now()));
            $lines[] = sprintf('- %s  Job Order %s%s -- by %s, submitted %s (%d days ago)',
                $r->service_record_number ?? $r->id,
                $r->jobOrder?->job_order_number ?? '-',
                $r->jobOrder?->customer ? ' ('.$r->jobOrder->customer->name.')' : '',
                $r->employee?->full_name ?? 'unknown',
                Carbon::parse($r->submitted_at)->format('d/m/Y'),
                $days,
            );
        }
        $lines[] = '';
        $lines[] = 'Approve them under Operations -> Service Record Approval.';
        $lines[] = '';
        $lines[] = 'This reminder goes out at 8:45 am on any day some are overdue.';

        return implode("\n", $lines);
    }
}
