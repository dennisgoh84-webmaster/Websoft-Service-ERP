<?php

use App\Services\EmailInbox;
use App\Services\ServiceRecordReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Email Inbox: read the helpdesk mailbox now (the screen also checks it
// when opened; the scheduler below runs it every five minutes).
Artisan::command('email-inbox:check', function () {
    $r = EmailInbox::check(force: true);
    if (! $r['configured']) {
        $this->warn('The helpdesk mailbox has no IMAP settings (Maintenance -> System Email).');

        return 1;
    }
    if ($r['error']) {
        $this->error($r['error']);

        return 1;
    }
    $this->info("{$r['added']} new email(s).");

    return 0;
})->purpose('Read new emails from the helpdesk mailbox into the Email Inbox');

// SRV-019: email the approvers about Service Records waiting more than a
// week for approval (Dennis, 2026-09-26, #50). Scheduled below for 08:45.
Artisan::command('service-records:remind-overdue', function () {
    $report = ServiceRecordReminder::sendOverdueApprovals();
    if ($report === []) {
        $this->info('No Service Record is overdue for approval.');
    }
    foreach ($report as $companyId => $r) {
        $this->line($r['sent_to']
            ? "{$r['overdue']} overdue -- sent to ".implode(', ', $r['sent_to'])
            : "{$r['overdue']} overdue -- not sent: {$r['skipped']}");
    }

    return 0;
})->purpose('Email the Service Record approvers about records waiting over a week');

// The server's timer (Dennis, 2026-09-26, #50: "Add a timer to the
// server"): the `scheduler` service in docker-compose.yml runs
// `php artisan schedule:work`, which runs these at their times. Times
// are Singapore time.
Schedule::command('service-records:remind-overdue')
    ->dailyAt('08:45')->timezone('Asia/Singapore')
    ->withoutOverlapping();
Schedule::call(fn () => EmailInbox::check(force: true))
    ->name('email-inbox-check')->everyFiveMinutes()
    ->withoutOverlapping();
