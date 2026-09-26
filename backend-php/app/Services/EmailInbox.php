<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\InboxEmail;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\SystemMailSetting;
use App\Models\User;
use App\Services\Mail\ImapMailbox;
use App\Services\Mail\MailboxReadError;
use App\Services\Mail\MimeMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Email Inbox (Dennis, 2026-09-26: the add-ins must wait for HTTPS, "I
 * need without https first"). The server reads the helpdesk mailbox --
 * Maintenance -> System Email, its IMAP settings -- and keeps each new
 * email as an InboxEmail for staff to Log as Incident, Convert to Job
 * Order (the add-ins' own logic, IncidentService) or Dismiss.
 *
 * The mailbox is read, never changed: nothing is marked read, moved or
 * deleted. Reading carries on from the last UID read; if the mailbox's
 * UIDVALIDITY changes, or on the very first read, it starts from the
 * emails of the last FIRST_READ_DAYS days.
 *
 * There is no background job on the server (docker-compose has none), so
 * the mailbox is checked when the Email Inbox is opened -- at most once a
 * minute -- and when staff press Check now; `php artisan
 * email-inbox:check` does the same from a cron if one is wanted.
 */
class EmailInbox
{
    /** The first read goes back a week, not the whole mailbox (Dennis confirmed 2026-09-26, open-business-decisions #49). */
    public const FIRST_READ_DAYS = 7;

    /** At most this many emails are read per check, oldest first; the next check carries on. */
    public const MAX_PER_CHECK = 50;

    public const MIN_SECONDS_BETWEEN_CHECKS = 60;

    public static function mailbox(): ?SystemMailSetting
    {
        return SystemMailSetting::find(SystemMailSetting::PURPOSE_HELPDESK);
    }

    /**
     * Reads new emails from the helpdesk mailbox.
     *
     * @param  (callable(SystemMailSetting): ImapMailbox)|null  $open  how to open the mailbox (tests pass a scripted one)
     * @return array{configured: bool, checked: bool, added: int, error: ?string}
     */
    public static function check(bool $force = false, ?callable $open = null): array
    {
        $box = self::mailbox();
        if ($box === null || ! $box->isImapConfigured()) {
            return ['configured' => false, 'checked' => false, 'added' => 0, 'error' => null];
        }
        if (! $force && $box->imap_last_checked_at !== null
            && Carbon::parse($box->imap_last_checked_at)->gt(Carbon::now()->subSeconds(self::MIN_SECONDS_BETWEEN_CHECKS))) {
            return ['configured' => true, 'checked' => false, 'added' => 0, 'error' => null];
        }

        $lock = Cache::lock('email-inbox-check', 120);
        if (! $lock->get()) {
            return ['configured' => true, 'checked' => false, 'added' => 0, 'error' => null];
        }

        $added = 0;
        $error = null;
        try {
            $open ??= fn (SystemMailSetting $b) => ImapMailbox::connect($b->imap_host, (int) ($b->imap_port ?: 993), (bool) $b->imap_use_ssl);
            $imap = $open($box);
            try {
                $imap->login($box->imap_username, $box->imap_password);
                $validity = $imap->examineInbox();
                $uids = ($box->imap_uid_validity === null || (int) $box->imap_uid_validity !== $validity || $box->imap_last_uid === null)
                    ? $imap->uidsSince(Carbon::now()->subDays(self::FIRST_READ_DAYS)->startOfDay())
                    : $imap->uidsAbove((int) $box->imap_last_uid);

                if ((int) $box->imap_uid_validity !== $validity) {
                    $box->imap_uid_validity = $validity;
                    $box->imap_last_uid = null;
                }
                foreach (array_slice($uids, 0, self::MAX_PER_CHECK) as $uid) {
                    $fetched = $imap->fetch($uid);
                    if (self::keep($box->purpose, $validity, $uid, $fetched['raw'], $fetched['internal_date'])) {
                        $added++;
                    }
                    $box->imap_last_uid = max((int) $box->imap_last_uid, $uid);
                }
            } finally {
                $imap->logout();
            }
        } catch (MailboxReadError $e) {
            $error = $e->getMessage();
        } finally {
            $box->imap_last_checked_at = Carbon::now();
            $box->imap_last_error = $error;
            $box->save();
            $lock->release();
        }

        return ['configured' => true, 'checked' => true, 'added' => $added, 'error' => $error];
    }

    /** Keeps one fetched email; false when it was already kept (same UID, or same Message-ID after a UIDVALIDITY change). */
    private static function keep(string $mailbox, int $validity, int $uid, string $raw, ?Carbon $internalDate): bool
    {
        if (InboxEmail::where('mailbox', $mailbox)->where('uid_validity', $validity)->where('uid', $uid)->exists()) {
            return false;
        }
        $m = MimeMessage::parse($raw);
        if ($m->messageId !== null && InboxEmail::where('mailbox', $mailbox)->where('message_id', $m->messageId)->exists()) {
            return false;
        }

        InboxEmail::create([
            'mailbox' => $mailbox,
            'uid_validity' => $validity,
            'uid' => $uid,
            'message_id' => $m->messageId,
            'from_name' => $m->fromName !== null ? mb_substr($m->fromName, 0, 255) : null,
            'from_email' => mb_substr($m->fromEmail !== '' ? $m->fromEmail : 'unknown', 0, 255),
            'subject' => mb_substr($m->subject !== '' ? $m->subject : '(no subject)', 0, 255),
            'received_at' => $internalDate ?? $m->date ?? Carbon::now(),
            'body_text' => $m->text,
            'attachment_names' => $m->attachmentNames ?: null,
            'status' => InboxEmail::STATUS_NEW,
        ]);

        return true;
    }

    /** Log as Incident. */
    public static function logAsIncident(InboxEmail $email, User $actor): InboxEmail
    {
        return self::handle($email, $actor, function (InboxEmail $e) use ($actor) {
            $r = IncidentService::logEmail($actor->company_id, $actor->id, $e->from_name, $e->from_email, $e->subject, self::description($e));

            return [$r['incident'], null, sprintf('Logged as Incident %s', $r['incident']->incident_number)];
        });
    }

    /** Convert to Job Order; falls back to an Incident alone, saying why, like the add-ins. */
    public static function convertToJobOrder(InboxEmail $email, User $actor): array
    {
        $fallback = null;
        $handled = self::handle($email, $actor, function (InboxEmail $e) use ($actor, &$fallback) {
            $r = IncidentService::convertEmailToJobOrder($actor->company_id, $actor->id, $e->from_name, $e->from_email, $e->subject, self::description($e));
            $fallback = $r['fallback_reason'];
            $what = $r['job_order']
                ? sprintf('Converted to Job Order %s (Incident %s)', $r['job_order']->job_order_number, $r['incident']->incident_number)
                : sprintf('Logged as Incident %s; no Job Order: %s', $r['incident']->incident_number, $fallback);

            return [$r['incident'], $r['job_order'], $what];
        });

        return ['email' => $handled, 'fallback_reason' => $fallback];
    }

    public static function dismiss(InboxEmail $email, User $actor, string $reason): InboxEmail
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException(422, 'Give the reason for dismissing this email.');
        }

        return DB::transaction(function () use ($email, $actor, $reason) {
            $e = InboxEmail::lockForUpdate()->findOrFail($email->id);
            self::requireNew($e);
            $e->update([
                'status' => InboxEmail::STATUS_DISMISSED, 'dismiss_reason' => $reason,
                'handled_by_user_id' => $actor->id, 'handled_at' => Carbon::now(), 'company_id' => $actor->company_id,
            ]);
            Audit::record(entityType: 'inbox_email', entityId: $e->id, action: 'dismissed', actorUserId: $actor->id,
                companyId: $actor->company_id, reason: $reason, details: "Email \"{$e->subject}\" from {$e->from_email} dismissed");

            return $e->fresh();
        });
    }

    /** A dismissed email back to New, e.g. dismissed by mistake. */
    public static function restore(InboxEmail $email, User $actor): InboxEmail
    {
        return DB::transaction(function () use ($email, $actor) {
            $e = InboxEmail::lockForUpdate()->findOrFail($email->id);
            if ($e->status !== InboxEmail::STATUS_DISMISSED) {
                throw new ApiException(409, 'Only a dismissed email can be restored.');
            }
            $old = ['dismiss_reason' => $e->dismiss_reason];
            $e->update(['status' => InboxEmail::STATUS_NEW, 'dismiss_reason' => null, 'handled_by_user_id' => null, 'handled_at' => null, 'company_id' => null]);
            Audit::record(entityType: 'inbox_email', entityId: $e->id, action: 'restored', actorUserId: $actor->id,
                companyId: $actor->company_id, details: "Email \"{$e->subject}\" from {$e->from_email} restored to New", oldValue: $old);

            return $e->fresh();
        });
    }

    /** @param callable(InboxEmail): array{0: Incident, 1: ?JobOrder, 2: string} $act */
    private static function handle(InboxEmail $email, User $actor, callable $act): InboxEmail
    {
        return DB::transaction(function () use ($email, $actor, $act) {
            // Locked, so two staff pressing at once make one Incident, not two.
            $e = InboxEmail::lockForUpdate()->findOrFail($email->id);
            self::requireNew($e);
            [$incident, $jobOrder, $what] = $act($e);
            $e->update([
                'status' => InboxEmail::STATUS_LOGGED, 'incident_id' => $incident->id, 'job_order_id' => $jobOrder?->id,
                'handled_by_user_id' => $actor->id, 'handled_at' => Carbon::now(), 'company_id' => $actor->company_id,
            ]);
            Audit::record(entityType: 'inbox_email', entityId: $e->id, action: 'logged', actorUserId: $actor->id,
                companyId: $actor->company_id, details: "Email \"{$e->subject}\" from {$e->from_email}: {$what}");

            return $e->fresh();
        });
    }

    private static function requireNew(InboxEmail $e): void
    {
        if ($e->status !== InboxEmail::STATUS_NEW) {
            $who = $e->handledBy?->full_name ?? 'someone';
            $when = $e->handled_at?->format('d/m/Y H:i') ?? '';
            throw new ApiException(409, $e->status === InboxEmail::STATUS_LOGGED
                ? "This email was already logged by {$who} on {$when}."
                : "This email was dismissed by {$who} on {$when}. Restore it first.");
        }
    }

    /** The Incident's description: the email's text, plus the names of any attachments left in the mailbox. */
    private static function description(InboxEmail $e): ?string
    {
        $text = (string) $e->body_text;
        if ($e->attachment_names) {
            $text .= ($text !== '' ? "\n\n" : '').'Attachments (in the helpdesk mailbox): '.implode(', ', $e->attachment_names);
        }

        return $text !== '' ? $text : null;
    }
}
