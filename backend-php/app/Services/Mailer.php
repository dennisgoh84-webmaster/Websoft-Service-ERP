<?php

namespace App\Services;

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
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            rawurlencode((string) config('websoft.smtp_username')),
            rawurlencode((string) config('websoft.smtp_password')),
            config('websoft.smtp_host'),
            config('websoft.smtp_port'),
        );

        return $dsn.(config('websoft.smtp_use_tls') ? '?require_tls=true' : '?auto_tls=false');
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

        $email = (new Email)
            ->from(sprintf('%s <%s>', config('websoft.smtp_from_name'), config('websoft.smtp_from_email')))
            ->to($toEmail)
            ->subject($subject)
            ->text($bodyText);

        if ($attachmentBytes !== null && $attachmentFilename !== null) {
            $email->attach($attachmentBytes, $attachmentFilename, $attachmentContentType);
        }

        try {
            Transport::fromDsn(self::dsn())->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new MailerException("Could not send email: {$e->getMessage()}", previous: $e);
        }
    }
}

class MailerNotConfiguredException extends \RuntimeException {}
class MailerException extends \RuntimeException {}
