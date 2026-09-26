<?php

use App\Services\EmailInbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Email Inbox: read the helpdesk mailbox now (the screen also checks it
// when opened). For a cron, if one is ever wanted.
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
