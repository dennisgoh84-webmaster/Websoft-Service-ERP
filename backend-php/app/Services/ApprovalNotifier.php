<?php

namespace App\Services;

use App\Models\ApprovalAuthority;
use App\Models\ApprovalAuthorityMember;
use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * "Email as each item arrives, plus the Approval Center" (Dennis,
 * 2026-09-26, decision page): when a document starts waiting on an
 * eApproval authority, each of its members with an email address is
 * told, through the system mailbox. Best effort -- a mailbox that is
 * not set up, or a send that fails, never stops the document; the
 * Approval Center lists it either way. Every attempt is in Event Logs.
 */
class ApprovalNotifier
{
    public static function requested(ApprovalRequest $request): void
    {
        $authority = ApprovalAuthority::find($request->authority_id);
        $members = User::whereIn('id', ApprovalAuthorityMember::where('authority_id', $request->authority_id)->pluck('user_id'))
            ->where('is_active', true)->whereNotNull('email')->where('email', '!=', '')->get();
        $what = $request->summary ?? str_replace('_', ' ', $request->entity_type);
        $subject = "For your approval: {$what}";
        $link = rtrim((string) config('app.url'), '/').'/approval-center';

        $sentTo = [];
        $skipped = null;
        if ($members->isEmpty()) {
            $skipped = 'no member of '.($authority?->name ?? 'the authority').' has an email address';
        } elseif (! Mailer::isConfigured()) {
            $skipped = 'the system mailbox is not set up (Maintenance -> System Email)';
        } else {
            foreach ($members as $member) {
                try {
                    Mailer::send($member->email, $subject, "Hello {$member->full_name},\n\n"
                        ."{$what} is waiting for your approval ({$authority?->name}).\n\n"
                        ."Open the Approval Center to approve or reject it:\n{$link}\n");
                    $sentTo[] = $member->email;
                } catch (\Throwable $e) {
                    $skipped = $e->getMessage();
                }
            }
        }

        Audit::record(
            entityType: 'approval_request',
            entityId: $request->id,
            action: $sentTo ? 'approval_emailed' : 'approval_email_not_sent',
            actorUserId: null,
            actorName: 'System (eApproval)',
            companyId: $request->company_id,
            details: $sentTo ? "{$subject} -- sent to ".implode(', ', $sentTo) : "{$subject} -- not sent: {$skipped}",
        );
    }
}
