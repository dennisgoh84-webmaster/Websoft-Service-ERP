<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\Mailer;
use App\Services\MailerException;
use App\Services\MailerNotConfiguredException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Email the self-test program's result (docs/self-test.md): pass or
 * fail, what failed and where, with a screenshot of each failure.
 *
 * Run by selftest/run-server.sh against the REAL stack after each
 * nightly run, so it goes out through the System Email mailbox Dennis
 * set up (Maintenance -> System Email) -- the self-test's own throwaway
 * stack has no mailbox, on purpose, so nothing it keys in can email
 * anyone. The result arrives as the JSON the runner writes
 * (selftest/runner/report.mjs), on stdin or from --file.
 */
class SelfTestReport extends Command
{
    /** Screenshots attached at most; the rest are in the report on the server. */
    private const MAX_SCREENSHOTS = 15;

    protected $signature = 'selftest:report
        {--file= : The runner\'s report.json (default: read stdin)}
        {--error : Stdin is the log of a run that could not start, not a report.json}
        {--to=* : Recipient email address (repeatable; default SELFTEST_EMAIL_TO)}';

    protected $description = 'Email the self-test result, with screenshots of failures';

    public function handle(): int
    {
        $raw = $this->option('file') ? @file_get_contents((string) $this->option('file')) : stream_get_contents(STDIN);
        // A run that died before the runner wrote anything still gets an
        // email: run-server.sh pipes in the tail of its log instead.
        $report = $this->option('error')
            ? ['status' => 'error', 'started_at' => now()->toIso8601String(), 'error' => trim((string) $raw) ?: 'No log.']
            : json_decode((string) $raw, true);
        if (! is_array($report)) {
            $this->error('No self-test report to send: expected the runner\'s report.json.');

            return self::FAILURE;
        }

        $to = array_values(array_filter(array_map('trim', $this->option('to') ?: explode(',', (string) config('websoft.selftest_email_to')))));
        if ($to === []) {
            $this->error('No recipient: pass --to or set SELFTEST_EMAIL_TO in .env.');

            return self::FAILURE;
        }

        [$subject, $body, $attachments] = self::compose($report);
        try {
            foreach ($to as $address) {
                Mailer::sendWithAttachments($address, $subject, $body, $attachments);
            }
        } catch (MailerNotConfiguredException|MailerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Each report is its own Event Log entry (entity ids are UUIDs).
        Audit::record(
            'selftest', (string) Str::uuid(), 'selftest_report_sent', null,
            actorName: 'Self-test', details: $subject.' -- to '.implode(', ', $to),
        );
        $this->info("Sent \"{$subject}\" to ".implode(', ', $to).'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: string, 1: string, 2: list<array{filename: string, bytes: string, content_type: string}>}
     */
    public static function compose(array $report): array
    {
        $summary = $report['summary'] ?? [];
        $passed = (int) ($summary['passed'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $status = (string) ($report['status'] ?? 'error');
        $when = isset($report['started_at']) ? Carbon::parse($report['started_at'])->setTimezone(config('app.timezone'))->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $server = (string) ($report['server'] ?? 'the test server');

        $subject = match ($status) {
            'passed' => "Websoft self-test PASSED: all {$passed} checks ({$when})",
            'failed' => "Websoft self-test FAILED: {$failed} of ".($passed + $failed)." checks ({$when})",
            default => "Websoft self-test could not run ({$when})",
        };

        $lines = [$subject, '', "Server: {$server}"];
        if (! empty($report['commit'])) {
            $lines[] = 'Code: '.$report['commit'];
        }
        if (isset($report['duration_s'])) {
            $lines[] = 'Took: '.gmdate('G\h i\m s\s', (int) $report['duration_s']);
        }
        $lines[] = "Passed: {$passed}   Failed: {$failed}   Skipped: {$skipped}";

        if ($status === 'error' || ! empty($report['error'])) {
            $lines[] = '';
            $lines[] = 'The run stopped before it finished:';
            $lines[] = (string) ($report['error'] ?? 'unknown error');
        }

        $attachments = [];
        $failures = $report['failures'] ?? [];
        if ($failures !== []) {
            $lines[] = '';
            $lines[] = 'What failed:';
            foreach ($failures as $i => $f) {
                $n = $i + 1;
                $where = trim(($f['profile'] ?? '').' '.($f['screen'] ?? ''));
                $lines[] = '';
                $lines[] = "{$n}. {$f['name']}".($where !== '' ? "  [{$where}]" : '');
                $lines[] = '   '.str_replace("\n", "\n   ", (string) ($f['message'] ?? ''));
                if (! empty($f['screenshot']) && count($attachments) < self::MAX_SCREENSHOTS) {
                    $filename = sprintf('%02d-%s.png', $n, trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($f['profile'].' '.$f['name'])), '-'));
                    $attachments[] = ['filename' => $filename, 'bytes' => base64_decode((string) $f['screenshot']), 'content_type' => 'image/png'];
                    $lines[] = "   Screenshot: {$filename}";
                }
            }
        }

        if (! empty($report['warnings'])) {
            $lines[] = '';
            $lines[] = 'Warnings (not failures):';
            foreach (array_slice($report['warnings'], 0, 30) as $w) {
                $lines[] = '- '.trim(($w['profile'] ?? '').' '.($w['screen'] ?? '')).': '.($w['message'] ?? '');
            }
        }

        if (! empty($report['report_path'])) {
            $lines[] = '';
            $lines[] = 'Full report on the server: '.$report['report_path'];
        }

        return [$subject, implode("\n", $lines)."\n", $attachments];
    }
}
