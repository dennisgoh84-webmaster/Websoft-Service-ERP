<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SystemMailSetting;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Outbound email over SMTP. Mirrors backend/app/services/mailer.py:
 * unconfigured by default (see config/websoft.php) -- until real SMTP
 * settings are set, send() throws MailerNotConfiguredException so the
 * API returns a clear error instead of silently pretending to have
 * sent anything.
 *
 * Attachments (2026-09-14): the "Email X" document endpoints attach the
 * document as a PDF, exactly like Python's `send_email`'s
 * attachment_filename / attachment_bytes / attachment_content_type
 * arguments -- see App\Services\DocumentEmail, which is the only caller
 * that passes them. The 3-argument calls (OTP, password reset) are
 * unchanged.
 *
 * THREE MAILBOXES, DELIBERATELY SEPARATE (Dennis, 2026-09-15), with NO
 * fallback between them:
 *
 *   send() / isConfigured()          -- the SYSTEM "otp" mailbox
 *     (system_mail_settings, purpose=otp; .env's SMTP_* keys as the
 *     fallback for THIS purpose only, so an install configured the old
 *     way keeps working). Login one-time codes, password resets and
 *     portal invites, which run BEFORE a company is chosen, so they
 *     could not resolve a company mailbox even in principle.
 *
 *   sendFromHelpdesk() / isHelpdeskConfigured() -- the SYSTEM
 *     "helpdesk" mailbox (purpose=helpdesk, database only). The
 *     acknowledgement sent to the person whose email the Outlook
 *     Add-in turned into an Incident or Job Order.
 *
 *   sendAs() / isConfiguredFor()     -- the COMPANY's own mailbox from
 *     its Company Setup row. Used by the customer-facing document
 *     emails.
 *
 * TESTS: fake() swaps the transport for an in-memory list, so a test
 * can assert what would have been sent without an SMTP server.
 *
 * Why no fallback: a customer-facing invoice sent from the system
 * mailbox would come from the wrong domain, failing SPF/DKIM at the
 * receiving server -- so it lands in spam or is rejected, and if it
 * does arrive it carries the wrong brand. A clear "not configured for
 * this company" error is strictly better than a silently misdelivered
 * invoice. The cost, accepted knowingly, is that a newly created
 * company has document email switched off until someone fills its
 * mailbox in.
 */
class Mailer
{
    public static function isConfigured(): bool
    {
        $s = self::systemSettings();

        return (bool) ($s['host'] && $s['from_email']);
    }

    public static function isHelpdeskConfigured(): bool
    {
        $s = self::helpdeskSettings();

        return (bool) ($s['host'] && $s['from_email']);
    }

    /**
     * Where the "otp" mailbox is currently coming from -- shown on the
     * System Email screen so an operator can tell whether the row they
     * are looking at is live or whether .env is still in charge.
     */
    public static function otpSource(): string
    {
        $row = self::row(SystemMailSetting::PURPOSE_OTP);
        if ($row?->isConfigured()) {
            return 'database';
        }

        return (config('websoft.smtp_host') && config('websoft.smtp_from_email')) ? 'env' : 'none';
    }

    /**
     * The Symfony Mailer DSN for the configured SMTP account.
     *
     * BUG FIX (2026-09-14): this used to read
     * `$scheme = config('websoft.smtp_use_tls') ? 'smtp' : 'smtp'` --
     * both branches identical, so `SMTP_USE_TLS=false` was inert and
     * TLS could never actually be turned off. Python's mailer.py calls
     * `smtp.starttls()` only when `smtp_use_tls` is true, so the flag
     * now maps onto Symfony's own STARTTLS controls:
     *
     * - true  -> `smtp://...?require_tls=true`: a plain connection that
     *   upgrades via STARTTLS, and *fails* if the server won't -- the
     *   same contract as Python's unconditional `starttls()` call,
     *   which raises when STARTTLS isn't available.
     * - false -> `smtp://...?auto_tls=false`: Symfony's opportunistic
     *   STARTTLS is switched off, so the session stays plain, like
     *   Python skipping the `starttls()` call entirely.
     *
     * Deliberately NOT the `smtps://` scheme for the true case: that is
     * implicit TLS-on-connect (SMTPS, normally port 465), a different
     * wire protocol from the STARTTLS upgrade Python performs.
     */
    public static function dsn(): string
    {
        return self::dsnFor(self::systemSettings());
    }

    /**
     * @param  array<string, mixed>  $s
     */
    public static function dsnFor(array $s): string
    {
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            rawurlencode((string) ($s['username'] ?? '')),
            rawurlencode((string) ($s['password'] ?? '')),
            $s['host'],
            (int) ($s['port'] ?? 587),
        );

        return $dsn.(($s['use_tls'] ?? true) ? '?require_tls=true' : '?auto_tls=false');
    }

    /**
     * The "otp" mailbox: the database row when it is configured,
     * otherwise .env. The fallback exists so an install set up before
     * system_mail_settings existed keeps sending login codes until its
     * row is filled in; it is the ONLY fallback in this class.
     *
     * @return array<string, mixed>
     */
    private static function systemSettings(): array
    {
        $row = self::row(SystemMailSetting::PURPOSE_OTP);
        if ($row?->isConfigured()) {
            return self::rowSettings($row);
        }

        return [
            'host' => config('websoft.smtp_host'),
            'port' => config('websoft.smtp_port'),
            'username' => config('websoft.smtp_username'),
            'password' => config('websoft.smtp_password'),
            'use_tls' => config('websoft.smtp_use_tls'),
            'from_email' => config('websoft.smtp_from_email'),
            'from_name' => config('websoft.smtp_from_name'),
        ];
    }

    /**
     * The "helpdesk" mailbox: database only. There is no .env
     * equivalent to fall back to, and deliberately no fallback to the
     * otp mailbox either -- a support acknowledgement should come from
     * the support desk, not from the address that sends login codes.
     *
     * @return array<string, mixed>
     */
    private static function helpdeskSettings(): array
    {
        $row = self::row(SystemMailSetting::PURPOSE_HELPDESK);

        return $row ? self::rowSettings($row) : ['host' => null, 'from_email' => null];
    }

    /**
     * Null when the table does not exist yet (a request served mid-
     * migration, or a unit test that never migrated), so a missing
     * table degrades to the .env fallback instead of a 500 on login.
     */
    private static function row(string $purpose): ?SystemMailSetting
    {
        try {
            return SystemMailSetting::find($purpose);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function rowSettings(SystemMailSetting $row): array
    {
        return [
            'host' => $row->host,
            'port' => $row->port,
            'username' => $row->username,
            'password' => $row->password,
            'use_tls' => $row->use_tls,
            'from_email' => $row->from_email,
            'from_name' => $row->from_name ?: config('websoft.smtp_from_name'),
        ];
    }

    /**
     * Send from the helpdesk mailbox. Refuses, rather than borrowing
     * another mailbox, when it is not configured -- see the class
     * docblock.
     */
    public static function sendFromHelpdesk(string $toEmail, string $subject, string $bodyText): void
    {
        if (! self::isHelpdeskConfigured()) {
            throw new MailerNotConfiguredException(
                'The Helpdesk mailbox is not configured. Set it under Maintenance -> System Email.'
            );
        }

        self::dispatch(self::helpdeskSettings(), $toEmail, $subject, $bodyText, null, null, 'application/pdf');
    }

    /**
     * Send a test message through one system mailbox, so the System
     * Email screen can prove a row works before anything depends on it.
     */
    public static function sendSystemTest(string $purpose, string $toEmail, string $subject, string $bodyText): void
    {
        $settings = $purpose === SystemMailSetting::PURPOSE_HELPDESK ? self::helpdeskSettings() : self::systemSettings();
        if (! ($settings['host'] && $settings['from_email'])) {
            throw new MailerNotConfiguredException("The {$purpose} mailbox is not configured yet.");
        }

        self::dispatch($settings, $toEmail, $subject, $bodyText, null, null, 'application/pdf');
    }

    // ── Test seam ───────────────────────────────────────────────────

    /** @var list<array<string, mixed>>|null */
    private static ?array $sent = null;

    /** Capture sends in memory instead of opening an SMTP connection. */
    public static function fake(): void
    {
        self::$sent = [];
    }

    /** @return list<array<string, mixed>> */
    public static function sent(): array
    {
        return self::$sent ?? [];
    }

    public static function restore(): void
    {
        self::$sent = null;
    }

    /** @return array<string, mixed> */
    private static function companySettings(Company $company): array
    {
        return [
            'host' => $company->smtp_host,
            'port' => $company->smtp_port,
            'username' => $company->smtp_username,
            'password' => $company->smtp_password,
            'use_tls' => $company->smtp_use_tls,
            'from_email' => $company->smtp_from_email,
            // Falls back to the company's own name, not to the system
            // sender -- it is still this company's mailbox either way.
            'from_name' => $company->smtp_from_name ?: $company->name,
        ];
    }

    /** Has this company had its own mailbox set up? */
    public static function isConfiguredFor(Company $company): bool
    {
        return (bool) ($company->smtp_host && $company->smtp_from_email);
    }

    /**
     * Send from THIS COMPANY's mailbox. Never falls back to the system
     * one -- see the class docblock.
     */
    public static function sendAs(
        Company $company,
        string $toEmail,
        string $subject,
        string $bodyText,
        ?string $attachmentFilename = null,
        ?string $attachmentBytes = null,
        string $attachmentContentType = 'application/pdf',
    ): void {
        if (! self::isConfiguredFor($company)) {
            throw new MailerNotConfiguredException(
                "Email is not configured for {$company->name}. Set its SMTP host and "
                .'From address under Company Setup -> Maintenance.'
            );
        }

        self::dispatch(
            self::companySettings($company),
            $toEmail, $subject, $bodyText,
            $attachmentFilename, $attachmentBytes, $attachmentContentType,
        );
    }

    public static function send(
        string $toEmail,
        string $subject,
        string $bodyText,
        ?string $attachmentFilename = null,
        ?string $attachmentBytes = null,
        string $attachmentContentType = 'application/pdf',
    ): void {
        if (! self::isConfigured()) {
            throw new MailerNotConfiguredException(
                'Email sending is not configured yet. Add SMTP_HOST / SMTP_USERNAME / '
                .'SMTP_PASSWORD / SMTP_FROM_EMAIL to backend-php/.env -- see DEV_SETUP.md.'
            );
        }

        self::dispatch(
            self::systemSettings(),
            $toEmail, $subject, $bodyText,
            $attachmentFilename, $attachmentBytes, $attachmentContentType,
        );
    }

    /**
     * The system mailbox with several attachments -- the self-test's
     * nightly report (docs/self-test.md), which attaches a screenshot
     * of each failure. Same mailbox, same refusal when unconfigured, as
     * send().
     *
     * @param  list<array{filename: string, bytes: string, content_type: string}>  $attachments
     */
    public static function sendWithAttachments(string $toEmail, string $subject, string $bodyText, array $attachments): void
    {
        if (! self::isConfigured()) {
            throw new MailerNotConfiguredException(
                'The system mailbox is not configured. Set it under Maintenance -> System Email.'
            );
        }

        self::dispatch(self::systemSettings(), $toEmail, $subject, $bodyText, null, null, 'application/pdf', $attachments);
    }

    /**
     * The one place a message is actually handed to a transport --
     * shared so the system and company paths cannot drift in how they
     * build or send a message, only in which settings they use.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function dispatch(
        array $settings,
        string $toEmail,
        string $subject,
        string $bodyText,
        ?string $attachmentFilename,
        ?string $attachmentBytes,
        string $attachmentContentType,
        array $moreAttachments = [],
    ): void {
        $email = (new Email)
            ->from(sprintf('%s <%s>', $settings['from_name'], $settings['from_email']))
            ->to($toEmail)
            ->subject($subject)
            ->text($bodyText);

        if ($attachmentBytes !== null && $attachmentFilename !== null) {
            $email->attach($attachmentBytes, $attachmentFilename, $attachmentContentType);
        }
        foreach ($moreAttachments as $a) {
            $email->attach($a['bytes'], $a['filename'], $a['content_type']);
        }

        if (self::$sent !== null) {
            self::$sent[] = [
                'from' => $settings['from_email'],
                'to' => $toEmail,
                'subject' => $subject,
                'body' => $bodyText,
                'attachment' => $attachmentFilename,
                'attachments' => array_map(fn ($a) => $a['filename'], $moreAttachments),
            ];

            return;
        }

        // Symfony's SMTP transport takes its connect timeout from PHP's
        // default_socket_timeout (60s). An unreachable host would hold
        // a request -- and the "Send test email" button -- for a full
        // minute before failing, so it is bounded here for the duration
        // of the send and put back afterwards.
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '15');
        try {
            Transport::fromDsn(self::dsnFor($settings))->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new MailerException("Could not send email: {$e->getMessage()}", previous: $e);
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }
}

class MailerNotConfiguredException extends \RuntimeException {}
class MailerException extends \RuntimeException {}
