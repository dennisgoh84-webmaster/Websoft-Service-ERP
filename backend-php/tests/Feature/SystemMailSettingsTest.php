<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\SystemMailSetting;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Mailer;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two system mailboxes moved out of .env into system_mail_settings
 * (Dennis, 2026-09-15), and the Helpdesk one's first use: the
 * acknowledgement the Outlook Add-in conversions send.
 */
class SystemMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'websoft.smtp_host' => null,
            'websoft.smtp_from_email' => null,
        ]);
        $this->company = Company::factory()->create();
        foreach (['core_administration', 'service_operations'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $key], ['enabled' => true]);
        }
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->ownerToken = $this->post('/api/auth/login', [
            'username' => $owner->email, 'password' => 'demo1234',
        ])->json('access_token');
    }

    protected function tearDown(): void
    {
        Mailer::restore();
        parent::tearDown();
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->ownerToken)];
    }

    private function configureHelpdesk(): void
    {
        SystemMailSetting::create([
            'purpose' => 'helpdesk', 'host' => 'smtp.example.test', 'port' => 587,
            'username' => 'support@webmaster.example', 'password' => 'pw',
            'use_tls' => true, 'from_email' => 'support@webmaster.example', 'from_name' => 'Webmaster Support',
        ]);
    }

    // ── The screen's API ────────────────────────────────────────────

    public function test_both_mailboxes_start_unconfigured_with_no_env_fallback(): void
    {
        $this->getJson('/api/system-mail', $this->headers())->assertOk()
            ->assertJsonPath('otp.configured', false)
            ->assertJsonPath('otp.source', 'none')
            ->assertJsonPath('helpdesk.configured', false)
            ->assertJsonPath('helpdesk.source', 'none');
    }

    public function test_saving_the_otp_mailbox_makes_it_live_and_hides_the_password(): void
    {
        $this->patchJson('/api/system-mail/otp', [
            'host' => 'smtp.office365.com',
            'username' => 'noreply@webmaster.example',
            'password' => 'super-secret',
            'from_email' => 'noreply@webmaster.example',
        ], $this->headers())->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('source', 'database')
            ->assertJsonPath('password_set', true)
            ->assertJsonMissingPath('password');

        $this->assertTrue(Mailer::isConfigured());
        $this->assertStringStartsWith('smtp://noreply%40webmaster.example:super-secret@smtp.office365.com:587', Mailer::dsn());

        // Encrypted at rest: a database dump does not hand over the account.
        $raw = (string) DB::table('system_mail_settings')->where('purpose', 'otp')->value('password');
        $this->assertStringNotContainsString('super-secret', $raw);

        // And the audit trail records THAT it changed, never the value.
        $entry = AuditLogEntry::where('entity_type', 'system_mail_setting')->where('action', 'updated')->firstOrFail();
        $this->assertStringNotContainsString('super-secret', (string) $entry->new_value);
        $this->assertStringContainsString('(set)', (string) $entry->new_value);
    }

    public function test_env_stays_the_otp_fallback_until_the_row_is_configured(): void
    {
        config(['websoft.smtp_host' => 'env.example.test', 'websoft.smtp_from_email' => 'env@example.test']);

        $this->getJson('/api/system-mail', $this->headers())->assertOk()
            ->assertJsonPath('otp.configured', true)
            ->assertJsonPath('otp.source', 'env');
        $this->assertStringContainsString('env.example.test', Mailer::dsn());

        // A saved row takes over; .env is no longer consulted.
        $this->patchJson('/api/system-mail/otp', [
            'host' => 'db.example.test', 'from_email' => 'db@example.test',
        ], $this->headers())->assertOk()->assertJsonPath('source', 'database');
        $this->assertStringContainsString('db.example.test', Mailer::dsn());
        $this->assertStringNotContainsString('env.example.test', Mailer::dsn());
    }

    public function test_the_helpdesk_mailbox_never_borrows_the_otp_one(): void
    {
        $this->patchJson('/api/system-mail/otp', [
            'host' => 'smtp.office365.com', 'from_email' => 'noreply@webmaster.example',
        ], $this->headers())->assertOk();

        $this->assertTrue(Mailer::isConfigured());
        $this->assertFalse(Mailer::isHelpdeskConfigured());
        $this->postJson('/api/system-mail/helpdesk/test-email', ['to_email' => 'me@example.test'], $this->headers())
            ->assertStatus(422);
    }

    public function test_a_blank_password_in_a_patch_leaves_the_stored_one_alone_and_null_clears_it(): void
    {
        $this->patchJson('/api/system-mail/helpdesk', [
            'host' => 'h', 'from_email' => 'a@b.test', 'password' => 'keep-me',
        ], $this->headers())->assertOk()->assertJsonPath('password_set', true);

        $this->patchJson('/api/system-mail/helpdesk', ['host' => 'h2'], $this->headers())
            ->assertOk()->assertJsonPath('password_set', true);
        $this->assertSame('keep-me', SystemMailSetting::find('helpdesk')->password);

        $this->patchJson('/api/system-mail/helpdesk', ['password' => null], $this->headers())
            ->assertOk()->assertJsonPath('password_set', false);
    }

    public function test_imap_settings_save_alongside_smtp_and_hide_the_password(): void
    {
        $this->patchJson('/api/system-mail/otp', [
            'imap_host' => 'imap.office365.com',
            'imap_username' => 'noreply@webmaster.example',
            'imap_password' => 'imap-secret',
        ], $this->headers())->assertOk()
            ->assertJsonPath('imap_host', 'imap.office365.com')
            ->assertJsonPath('imap_password_set', true)
            ->assertJsonPath('imap_configured', true)
            ->assertJsonMissingPath('imap_password');

        // Encrypted at rest, same as the SMTP password.
        $raw = (string) DB::table('system_mail_settings')->where('purpose', 'otp')->value('imap_password');
        $this->assertStringNotContainsString('imap-secret', $raw);
    }

    public function test_test_imap_requires_saved_credentials_first(): void
    {
        $this->postJson('/api/system-mail/otp/test-imap', [], $this->headers())->assertStatus(422);
    }

    public function test_test_imap_reports_a_connection_failure_clearly(): void
    {
        $this->patchJson('/api/system-mail/otp', [
            'imap_host' => '127.0.0.1', 'imap_port' => 1,
            'imap_username' => 'someone', 'imap_password' => 'pw',
        ], $this->headers())->assertOk();

        $this->postJson('/api/system-mail/otp/test-imap', [], $this->headers())
            ->assertStatus(502)
            ->assertJsonPath('detail', fn ($detail) => str_contains($detail, 'Could not connect'));
    }

    public function test_an_unknown_purpose_is_404_and_a_view_only_user_cannot_change_anything(): void
    {
        $this->patchJson('/api/system-mail/marketing', ['host' => 'x'], $this->headers())->assertStatus(404);

        $group = Group::create(['company_id' => $this->company->id, 'name' => 'Readers']);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'core_administration', 'access_level' => 'view']);
        $viewer = User::factory()->for($this->company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $viewer->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $viewer->email, 'password' => 'demo1234'])->json('access_token');

        $this->getJson('/api/system-mail', $this->headers($token))->assertOk();
        $this->patchJson('/api/system-mail/otp', ['host' => 'x'], $this->headers($token))->assertStatus(403);
    }

    public function test_test_email_goes_through_the_named_mailbox(): void
    {
        Mailer::fake();
        $this->configureHelpdesk();

        $this->postJson('/api/system-mail/helpdesk/test-email', ['to_email' => 'dennis@example.test'], $this->headers())
            ->assertOk()->assertJson(['sent' => true]);

        $sent = Mailer::sent();
        $this->assertCount(1, $sent);
        $this->assertSame('support@webmaster.example', $sent[0]['from']);
        $this->assertSame('dennis@example.test', $sent[0]['to']);
        $this->assertStringContainsString('Helpdesk', $sent[0]['subject']);
    }

    // ── The Helpdesk mailbox's first job: Outlook Add-in acknowledgements ──

    public function test_an_incident_logged_from_outlook_is_acknowledged_from_the_helpdesk_mailbox(): void
    {
        Mailer::fake();
        $this->configureHelpdesk();

        $response = $this->postJson('/api/incidents/from-email', [
            'sender_name' => 'Bob', 'sender_email' => 'bob@acme.test',
            'subject' => 'Printer offline', 'body' => 'Since this morning.',
        ], $this->headers())->assertOk()->assertJsonPath('acknowledgement_sent', true);

        $sent = Mailer::sent();
        $this->assertCount(1, $sent);
        $this->assertSame('support@webmaster.example', $sent[0]['from']);
        $this->assertSame('bob@acme.test', $sent[0]['to']);
        $this->assertStringContainsString($response->json('incident_number'), $sent[0]['subject']);
        $this->assertStringContainsString('Hi Bob,', $sent[0]['body']);
        $this->assertStringNotContainsString('Job Order', $sent[0]['body']);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $response->json('id'), 'action' => 'acknowledgement_emailed',
        ]);
    }

    public function test_a_job_order_opened_from_outlook_names_the_job_order_in_the_acknowledgement(): void
    {
        Mailer::fake();
        $this->configureHelpdesk();
        $customer = CompanyIndividual::factory()->for($this->company)->create();
        Contact::create(['customer_id' => $customer->id, 'name' => 'Carl', 'email' => 'carl@acme.test', 'is_active' => true]);
        Contract::factory()->for($this->company)->create(['customer_id' => $customer->id, 'status' => 'active']);

        $response = $this->postJson('/api/incidents/from-email/convert-to-job-order', [
            'sender_email' => 'carl@acme.test', 'subject' => 'Server is down',
        ], $this->headers())->assertOk()
            ->assertJsonPath('job_order_created', true)
            ->assertJsonPath('acknowledgement_sent', true);

        $body = Mailer::sent()[0]['body'];
        $this->assertStringContainsString($response->json('incident.incident_number'), Mailer::sent()[0]['subject']);
        $this->assertStringContainsString('A Job Order, JO', $body);
    }

    public function test_no_helpdesk_mailbox_means_no_acknowledgement_and_no_failure(): void
    {
        Mailer::fake();

        $this->postJson('/api/incidents/from-email', [
            'sender_email' => 'bob@acme.test', 'subject' => 'Printer offline',
        ], $this->headers())->assertOk()->assertJsonPath('acknowledgement_sent', false);

        $this->assertCount(0, Mailer::sent());
        $this->assertDatabaseMissing('audit_log_entries', ['action' => 'acknowledgement_emailed']);
    }

    public function test_a_refused_send_still_leaves_the_incident_created(): void
    {
        // A real transport against a host that does not exist: the send
        // fails, the conversion must not.
        SystemMailSetting::create([
            'purpose' => 'helpdesk', 'host' => '127.0.0.1', 'port' => 1,
            'from_email' => 'support@webmaster.example',
        ]);

        $response = $this->postJson('/api/incidents/from-email', [
            'sender_email' => 'bob@acme.test', 'subject' => 'Printer offline',
        ], $this->headers())->assertOk()->assertJsonPath('acknowledgement_sent', false);

        $this->assertDatabaseHas('incidents', ['id' => $response->json('id')]);
    }
}
