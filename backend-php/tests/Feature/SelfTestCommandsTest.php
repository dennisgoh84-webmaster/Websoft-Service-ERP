<?php

namespace Tests\Feature;

use App\Console\Commands\SelfTestReport;
use App\Models\AuditLogEntry;
use App\Services\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The self-test program's two backend commands (docs/self-test.md):
 * the database rebuild that must never touch a real database, and the
 * nightly pass/fail email.
 */
class SelfTestCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rebuild_refuses_any_database_not_named_selftest(): void
    {
        // The test database is websoft_php_erp_test*, so this must refuse
        // before touching anything -- the same refusal a mistyped
        // DB_DATABASE on a real server would get.
        $this->artisan('selftest:prepare-db')
            ->expectsOutputToContain('Refusing')
            ->assertFailed();
    }

    private function report(array $overrides = []): array
    {
        return array_merge([
            'status' => 'failed',
            'started_at' => '2026-09-26T18:30:00Z', // 02:30 on the 27th, Singapore time
            'duration_s' => 754,
            'commit' => 'abc1234 Something',
            'server' => 'websoft-test',
            'summary' => ['passed' => 180, 'failed' => 2, 'skipped' => 1],
            'failures' => [
                ['profile' => 'phone', 'name' => 'Screen /invoices', 'screen' => '/invoices', 'message' => 'Server call failed: GET /api/invoices -> 500', 'screenshot' => base64_encode('PNG1')],
                ['profile' => 'desktop', 'name' => 'Contract: add and activate', 'screen' => '/contracts', 'message' => 'Hours: keyed in "10", but the server stored "0".', 'screenshot' => null],
            ],
            'warnings' => [['profile' => 'desktop', 'screen' => '/prospects/:id', 'message' => 'No record to open.']],
        ], $overrides);
    }

    public function test_a_failed_run_says_what_failed_where_with_screenshots(): void
    {
        [$subject, $body, $attachments] = SelfTestReport::compose($this->report());

        $this->assertSame('Websoft self-test FAILED: 2 of 182 checks (27/09/2026 02:30)', $subject);
        $this->assertStringContainsString('1. Screen /invoices  [phone /invoices]', $body);
        $this->assertStringContainsString('Server call failed: GET /api/invoices -> 500', $body);
        $this->assertStringContainsString('2. Contract: add and activate  [desktop /contracts]', $body);
        $this->assertStringContainsString('Took: 0h 12m 34s', $body);
        $this->assertCount(1, $attachments);
        $this->assertSame('01-phone-screen-invoices.png', $attachments[0]['filename']);
        $this->assertSame('PNG1', $attachments[0]['bytes']);
    }

    public function test_a_clean_run_and_a_run_that_could_not_start(): void
    {
        [$subject] = SelfTestReport::compose($this->report(['status' => 'passed', 'summary' => ['passed' => 182, 'failed' => 0, 'skipped' => 0], 'failures' => []]));
        $this->assertSame('Websoft self-test PASSED: all 182 checks (27/09/2026 02:30)', $subject);

        [$subject, $body] = SelfTestReport::compose($this->report(['status' => 'error', 'error' => 'The self-test app did not come up.', 'failures' => []]));
        $this->assertSame('Websoft self-test could not run (27/09/2026 02:30)', $subject);
        $this->assertStringContainsString('The self-test app did not come up.', $body);
    }

    public function test_it_emails_every_recipient_from_the_system_mailbox_and_logs_it(): void
    {
        config(['websoft.smtp_host' => 'smtp.example', 'websoft.smtp_from_email' => 'noreply@webmaster.example']);
        Mailer::fake();
        $file = tempnam(sys_get_temp_dir(), 'st');
        file_put_contents($file, json_encode($this->report()));

        $this->artisan('selftest:report', ['--file' => $file, '--to' => ['dennis@webmaster.example', 'nico@webmaster.example']])->assertSuccessful();

        $sent = Mailer::sent();
        $this->assertSame(['dennis@webmaster.example', 'nico@webmaster.example'], array_column($sent, 'to'));
        $this->assertSame(['01-phone-screen-invoices.png'], $sent[0]['attachments']);
        $this->assertTrue(AuditLogEntry::where('action', 'selftest_report_sent')->exists());
        Mailer::restore();
    }

    public function test_a_run_that_could_not_start_still_emails_why(): void
    {
        config(['websoft.smtp_host' => 'smtp.example', 'websoft.smtp_from_email' => 'noreply@webmaster.example']);
        Mailer::fake();
        $log = tempnam(sys_get_temp_dir(), 'st');
        file_put_contents($log, "The self-test copy of the app did not build.\n\nLast lines of the log: apt-get failed");

        // run-server.sh pipes its log in with --error when there is no report.json.
        $this->artisan('selftest:report', ['--error' => true, '--file' => $log, '--to' => ['dennis@webmaster.example']])->assertSuccessful();

        $sent = Mailer::sent()[0];
        $this->assertStringStartsWith('Websoft self-test could not run', $sent['subject']);
        $this->assertStringContainsString('The self-test copy of the app did not build.', $sent['body']);
        Mailer::restore();
    }

    public function test_it_says_so_when_there_is_no_mailbox_or_no_recipient(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'st');
        file_put_contents($file, json_encode($this->report()));

        $this->artisan('selftest:report', ['--file' => $file])->expectsOutputToContain('No recipient')->assertFailed();

        config(['websoft.smtp_host' => null]);
        $this->artisan('selftest:report', ['--file' => $file, '--to' => ['dennis@webmaster.example']])
            ->expectsOutputToContain('system mailbox is not configured')->assertFailed();
    }
}
