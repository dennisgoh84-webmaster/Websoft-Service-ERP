<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\InboxEmail;
use App\Models\Incident;
use App\Models\ModuleCatalog;
use App\Models\SystemMailSetting;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\EmailInbox;
use App\Services\Mail\ImapMailbox;
use App\Services\Mail\MimeMessage;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Email Inbox (Dennis, 2026-09-26): the server reads the helpdesk mailbox
 * over IMAP -- no HTTPS needed -- and staff Log as Incident, Convert to
 * Job Order or Dismiss each email.
 */
class EmailInboxTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<resource> the scripted servers' ends, kept open */
    private array $servers = [];

    private Company $company;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $this->h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token')];
        SystemMailSetting::updateOrCreate(['purpose' => SystemMailSetting::PURPOSE_HELPDESK], [
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_username' => 'helpdesk@example.com',
            'imap_password' => 'secret', 'imap_use_ssl' => true,
        ]);
    }

    private function email(string $from, string $subject, string $body, string $messageId): string
    {
        return "From: {$from}\r\nTo: helpdesk@example.com\r\nSubject: {$subject}\r\nMessage-ID: <{$messageId}>\r\n"
            ."Date: Sat, 26 Sep 2026 09:15:00 +0800\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";
    }

    /**
     * An ImapMailbox talking to a scripted server: every reply is queued
     * up front, in the order the client's commands will ask for them.
     *
     * @param  list<string>  $replies
     */
    private function scripted(array $replies): ImapMailbox
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, implode('', $replies));
        $this->servers[] = $server;

        return new ImapMailbox($client);
    }

    /** @param array<int, string> $messages uid => raw message */
    private function imapSession(int $validity, string $searchUids, array $messages): array
    {
        $replies = ["* OK IMAP ready\r\n", "w1 OK LOGIN completed\r\n",
            "* 3 EXISTS\r\n* OK [UIDVALIDITY {$validity}] UIDs valid\r\nw2 OK [READ-ONLY] EXAMINE completed\r\n",
            "* SEARCH {$searchUids}\r\nw3 OK SEARCH completed\r\n"];
        $tag = 4;
        foreach ($messages as $uid => $raw) {
            $replies[] = "* 1 FETCH (UID {$uid} INTERNALDATE \"26-Sep-2026 09:15:00 +0800\" BODY[]<0> {".strlen($raw)."}\r\n{$raw})\r\nw{$tag} OK FETCH completed\r\n";
            $tag++;
        }
        $replies[] = "* BYE logging out\r\nw{$tag} OK LOGOUT completed\r\n";

        return $replies;
    }

    public function test_a_mime_email_is_read_into_sender_subject_text_and_attachment_names(): void
    {
        $raw = implode("\r\n", [
            'From: =?UTF-8?B?VGFuIEFoIEt1bg==?= <Ahkun@Client.COM>',
            'Subject: =?UTF-8?Q?Printer_=E2=80=93_not?= =?UTF-8?Q?_working?=',
            'Message-ID: <abc@client.com>',
            'Date: Sat, 26 Sep 2026 09:15:00 +0800',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            '--outer',
            'Content-Type: multipart/alternative; boundary=inner',
            '',
            '--inner',
            'Content-Type: text/plain; charset="UTF-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'The printer on level 3 shows =E2=80=9CPaper jam=E2=80=9D.',
            '--inner',
            'Content-Type: text/html; charset="UTF-8"',
            '',
            '<p>HTML copy</p>',
            '--inner--',
            '--outer',
            'Content-Type: image/png; name="photo.png"',
            'Content-Disposition: attachment; filename="photo.png"',
            'Content-Transfer-Encoding: base64',
            '',
            'iVBORw0KGgo=',
            '--outer--',
        ]);

        $m = MimeMessage::parse($raw);

        $this->assertSame('Tan Ah Kun', $m->fromName);
        $this->assertSame('ahkun@client.com', $m->fromEmail);
        $this->assertSame('Printer – not working', $m->subject);
        $this->assertSame('The printer on level 3 shows “Paper jam”.', $m->text);
        $this->assertSame(['photo.png'], $m->attachmentNames);
        $this->assertSame('2026-09-26 09:15', $m->date->format('Y-m-d H:i'));
    }

    public function test_an_html_only_email_in_another_charset_becomes_plain_text(): void
    {
        $html = base64_encode(mb_convert_encoding('<html><style>p{}</style><body><p>Caf&eacute; menu</p><p>Line two<br>Line three</p></body></html>', 'ISO-8859-1', 'UTF-8'));
        $raw = "From: shop@example.com\r\nSubject: Hello\r\nContent-Type: text/html; charset=ISO-8859-1\r\nContent-Transfer-Encoding: base64\r\n\r\n{$html}\r\n";

        $m = MimeMessage::parse($raw);

        $this->assertNull($m->fromName);
        $this->assertSame('shop@example.com', $m->fromEmail);
        $this->assertSame("Café menu\nLine two\nLine three", $m->text);
    }

    public function test_the_first_check_reads_the_last_week_and_later_checks_carry_on_from_the_last_uid(): void
    {
        $first = $this->imapSession(777, '11 12', [
            11 => $this->email('Dana <dana@example.com>', 'Server down', 'Please help', 'm11@x'),
            12 => $this->email('eve@example.com', 'Invoice query', 'Hi', 'm12@x'),
        ]);
        $r = EmailInbox::check(force: true, open: fn () => $this->scripted($first));

        $this->assertSame(['configured' => true, 'checked' => true, 'added' => 2, 'error' => null], $r);
        $this->assertSame(2, InboxEmail::where('status', 'new')->count());
        $box = SystemMailSetting::find('helpdesk');
        $this->assertSame(12, (int) $box->imap_last_uid);
        $this->assertSame(777, (int) $box->imap_uid_validity);

        // Next check: "UID 13:*" -- the server also returns 12 (always the newest), which is skipped.
        $next = $this->imapSession(777, '12 13', [13 => $this->email('Dana <dana@example.com>', 'Follow-up', 'Still down', 'm13@x')]);
        $r = EmailInbox::check(force: true, open: fn () => $this->scripted($next));
        $this->assertSame(1, $r['added']);
        $this->assertSame(3, InboxEmail::count());

        // Within a minute of the last check, opening the screen does not check again.
        $this->assertFalse(EmailInbox::check(open: fn () => $this->fail('should not connect'))['checked']);
    }

    public function test_a_mailbox_error_is_kept_and_shown_not_thrown(): void
    {
        $r = EmailInbox::check(force: true, open: fn () => $this->scripted(["* OK ready\r\n", "w1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n", "w2 OK\r\n"]));

        $this->assertTrue($r['checked']);
        $this->assertStringContainsString('Invalid credentials', $r['error']);
        $this->assertStringContainsString('Invalid credentials', SystemMailSetting::find('helpdesk')->imap_last_error);
    }

    public function test_without_imap_settings_the_inbox_says_so(): void
    {
        SystemMailSetting::find('helpdesk')->update(['imap_host' => null]);

        $this->getJson('/api/email-inbox', $this->h)->assertOk()->assertJsonPath('mailbox.configured', false)->assertJsonPath('emails', []);
    }

    private function inboxEmail(string $from, string $subject = 'Server down'): InboxEmail
    {
        static $uid = 100;

        return InboxEmail::create([
            'mailbox' => 'helpdesk', 'uid_validity' => 1, 'uid' => $uid++, 'from_name' => 'Dana', 'from_email' => $from,
            'subject' => $subject, 'received_at' => Carbon::now(), 'body_text' => 'Please help', 'attachment_names' => ['log.txt'],
            'status' => InboxEmail::STATUS_NEW,
        ]);
    }

    public function test_log_as_incident_matches_the_sender_and_can_only_happen_once(): void
    {
        SystemMailSetting::find('helpdesk')->update(['imap_last_checked_at' => Carbon::now()]); // no live check
        $customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme']);
        Contact::create(['customer_id' => $customer->id, 'name' => 'Dana', 'email' => 'dana@example.com']);
        $email = $this->inboxEmail('dana@example.com');

        $this->getJson('/api/email-inbox', $this->h)->assertOk()->assertJsonPath('emails.0.matched_company_individual', 'ACME');

        $r = $this->postJson("/api/email-inbox/{$email->id}/log-incident", [], $this->h)->assertOk()->json();
        $this->assertSame('logged', $r['status']);
        $incident = Incident::find($r['incident_id']);
        $this->assertSame($customer->id, $incident->customer_id);
        $this->assertSame(Incident::SOURCE_EMAIL, $incident->source);
        $this->assertStringContainsString('log.txt', $incident->description);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'inbox_email', 'entity_id' => $email->id, 'action' => 'logged']);

        $this->postJson("/api/email-inbox/{$email->id}/log-incident", [], $this->h)->assertStatus(409);
        $this->postJson("/api/email-inbox/{$email->id}/convert-to-job-order", [], $this->h)->assertStatus(409);
        $this->getJson('/api/email-inbox?status=logged', $this->h)->assertJsonPath('emails.0.incident_number', $incident->incident_number);
    }

    public function test_convert_to_job_order_opens_one_against_a_valid_contract_or_falls_back(): void
    {
        $customer = CompanyIndividual::factory()->for($this->company)->create();
        Contact::create(['customer_id' => $customer->id, 'name' => 'Dana', 'email' => 'dana@example.com']);
        Contract::factory()->for($this->company)->create(['customer_id' => $customer->id, 'status' => Contract::STATUS_ACTIVE]);

        $withContract = $this->postJson('/api/email-inbox/'.$this->inboxEmail('dana@example.com')->id.'/convert-to-job-order', [], $this->h)->assertOk()->json();
        $this->assertNotNull($withContract['job_order_number']);
        $this->assertNull($withContract['fallback_reason']);

        $stranger = $this->postJson('/api/email-inbox/'.$this->inboxEmail('who@nowhere.com')->id.'/convert-to-job-order', [], $this->h)->assertOk()->json();
        $this->assertNull($stranger['job_order_id']);
        $this->assertNotNull($stranger['incident_id']);
        $this->assertStringContainsString('who@nowhere.com', $stranger['fallback_reason']);
    }

    public function test_dismiss_needs_a_reason_and_can_be_restored_nothing_is_deleted(): void
    {
        $email = $this->inboxEmail('spam@example.com', 'Buy now');

        $this->postJson("/api/email-inbox/{$email->id}/dismiss", ['reason' => ''], $this->h)->assertStatus(422);
        $this->postJson("/api/email-inbox/{$email->id}/dismiss", ['reason' => 'Advert'], $this->h)->assertOk()->assertJsonPath('status', 'dismissed');
        $this->assertDatabaseHas('audit_log_entries', ['entity_id' => $email->id, 'action' => 'dismissed', 'reason' => 'Advert']);

        $this->postJson("/api/email-inbox/{$email->id}/restore", [], $this->h)->assertOk()->assertJsonPath('status', 'new');
        $this->assertDatabaseHas('inbox_emails', ['id' => $email->id, 'status' => 'new']);
        $this->postJson('/api/email-inbox/not-a-uuid/restore', [], $this->h)->assertStatus(404);
    }

    public function test_a_view_only_group_can_read_the_inbox_but_not_act(): void
    {
        SystemMailSetting::find('helpdesk')->update(['imap_last_checked_at' => Carbon::now()]);
        $email = $this->inboxEmail('dana@example.com');
        $group = Group::factory()->for($this->company)->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_operations'], ['name' => 'Service Operations', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => 'service_operations'], ['enabled' => true]);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::VIEW]);
        $clerk = User::factory()->for($this->company)->create(['role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $clerk->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $clerk->email, 'password' => 'demo1234'])->json('access_token')];

        $this->getJson('/api/email-inbox', $h)->assertOk()->assertJsonPath('emails.0.id', $email->id);
        $this->postJson("/api/email-inbox/{$email->id}/log-incident", [], $h)->assertStatus(403);
        $this->postJson("/api/email-inbox/{$email->id}/dismiss", ['reason' => 'x'], $h)->assertStatus(403);
    }
}
