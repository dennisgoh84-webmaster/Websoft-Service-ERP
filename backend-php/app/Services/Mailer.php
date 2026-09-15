<?php

namespace App\Services;

use App\Models\Company;
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
 * TWO MAILBOXES, DELIBERATELY SEPARATE (Dennis, 2026-09-15), with NO
 * fallback in either direction:
 *
 *   send() / isConfigured()          -- the SYSTEM mailbox from
 *     .env. Used by login OTP and password reset, which run BEFORE a
 *     company is chosen, so they could not resolve a company mailbox
 *     even in principle.
 *
 *   sendAs() / isConfiguredFor()     -- the COMPANY's own mailbox from
 *     its Company Setup row. Used by the customer-facing document
 *     emails.
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
        return (bool) (config('websoft.smtp_host') && config('websoft.smtp_from_email'));
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

    /** @return array<string, mixed> */
    private static function systemSettings(): array
    {
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
    ): void {
        $email = (new Email)
            ->from(sprintf('%s <%s>', $settings['from_name'], $settings['from_email']))
            ->to($toEmail)
            ->subject($subject)
            ->text($bodyText);

        if ($attachmentBytes !== null && $attachmentFilename !== null) {
            $email->attach($attachmentBytes, $attachmentFilename, $attachmentContentType);
        }

        try {
            Transport::fromDsn(self::dsnFor($settings))->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new MailerException("Could not send email: {$e->getMessage()}", previous: $e);
        }
    }
}

class MailerNotConfiguredException extends \RuntimeException {}
class MailerException extends \RuntimeException {}
