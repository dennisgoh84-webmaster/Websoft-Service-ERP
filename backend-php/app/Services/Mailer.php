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
 */
class Mailer
{
    public static function isConfigured(): bool
    {
        return (bool) (config('websoft.smtp_host') && config('websoft.smtp_from_email'));
    }

    public static function send(string $toEmail, string $subject, string $bodyText): void
    {
        if (! self::isConfigured()) {
            throw new MailerNotConfiguredException(
                'Email sending is not configured yet. Add SMTP_HOST / SMTP_USERNAME / '
                .'SMTP_PASSWORD / SMTP_FROM_EMAIL to backend-php/.env -- see DEV_SETUP.md.'
            );
        }

        $scheme = config('websoft.smtp_use_tls') ? 'smtp' : 'smtp';
        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode((string) config('websoft.smtp_username')),
            rawurlencode((string) config('websoft.smtp_password')),
            config('websoft.smtp_host'),
            config('websoft.smtp_port'),
        );

        $email = (new Email)
            ->from(sprintf('%s <%s>', config('websoft.smtp_from_name'), config('websoft.smtp_from_email')))
            ->to($toEmail)
            ->subject($subject)
            ->text($bodyText);

        try {
            Transport::fromDsn($dsn)->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new MailerException("Could not send email: {$e->getMessage()}", previous: $e);
        }
    }
}

class MailerNotConfiguredException extends \RuntimeException {}
class MailerException extends \RuntimeException {}
