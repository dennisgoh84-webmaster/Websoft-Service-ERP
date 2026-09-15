<?php

namespace Tests\Feature;

use App\Services\Mailer;
use App\Services\MailerNotConfiguredException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * App\Services\Mailer, mirroring backend/app/services/mailer.py.
 *
 * BUG FIX PINNED HERE (found 2026-09-14 while wiring up the "Email X"
 * document endpoints): the DSN builder read
 * `config('websoft.smtp_use_tls') ? 'smtp' : 'smtp'` -- both branches
 * identical -- so `SMTP_USE_TLS=false` was inert and TLS could never
 * be switched off. Python's mailer.py calls `smtp.starttls()` only
 * when the flag is true, so these tests assert the *resulting Symfony
 * transport*, not just the DSN string: STARTTLS required when the flag
 * is on, Symfony's opportunistic STARTTLS switched off when it is off.
 */
class MailerTest extends TestCase
{
    private function configure(bool $useTls): void
    {
        config([
            'websoft.smtp_host' => 'smtp.office365.com',
            'websoft.smtp_port' => 587,
            'websoft.smtp_username' => 'noreply@webmaster.example',
            'websoft.smtp_password' => 'p@ss word/1',
            'websoft.smtp_use_tls' => $useTls,
            'websoft.smtp_from_email' => 'noreply@webmaster.example',
            'websoft.smtp_from_name' => 'Web Master Consultancy',
        ]);
    }

    public function test_tls_on_requires_starttls_on_a_plain_smtp_connection(): void
    {
        $this->configure(true);

        $this->assertSame(
            'smtp://noreply%40webmaster.example:p%40ss%20word%2F1@smtp.office365.com:587?require_tls=true',
            Mailer::dsn(),
        );

        $transport = Transport::fromDsn(Mailer::dsn());
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired(), 'STARTTLS must be mandatory, like Python\'s unconditional starttls()');
        $this->assertTrue($transport->isAutoTls());
    }

    public function test_tls_off_really_disables_tls(): void
    {
        $this->configure(false);

        $this->assertSame(
            'smtp://noreply%40webmaster.example:p%40ss%20word%2F1@smtp.office365.com:587?auto_tls=false',
            Mailer::dsn(),
        );

        $transport = Transport::fromDsn(Mailer::dsn());
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        // The regression this pins: before the fix both branches of the
        // ternary produced the identical DSN, so this was true either way.
        $this->assertFalse($transport->isAutoTls(), 'SMTP_USE_TLS=false must actually switch STARTTLS off');
        $this->assertFalse($transport->isTlsRequired());
    }

    public function test_the_two_settings_produce_different_transports(): void
    {
        $this->configure(true);
        $on = Mailer::dsn();
        $this->configure(false);
        $off = Mailer::dsn();

        $this->assertNotSame($on, $off);
    }

    public function test_send_without_smtp_settings_fails_loudly_rather_than_pretending(): void
    {
        config(['websoft.smtp_host' => null, 'websoft.smtp_from_email' => null]);

        $this->assertFalse(Mailer::isConfigured());
        $this->expectException(MailerNotConfiguredException::class);
        Mailer::send('someone@example.com', 'Subject', 'Body');
    }

    public function test_is_configured_needs_both_a_host_and_a_from_address(): void
    {
        config(['websoft.smtp_host' => 'smtp.office365.com', 'websoft.smtp_from_email' => null]);
        $this->assertFalse(Mailer::isConfigured());

        config(['websoft.smtp_host' => null, 'websoft.smtp_from_email' => 'noreply@webmaster.example']);
        $this->assertFalse(Mailer::isConfigured());

        $this->configure(true);
        $this->assertTrue(Mailer::isConfigured());
    }
}
