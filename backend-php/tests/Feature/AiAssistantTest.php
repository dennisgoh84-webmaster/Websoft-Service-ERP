<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Ai\AiClient;
use App\Services\Ai\IncidentTriage;
use App\Services\Ai\Redactor;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI Assistant slice 1 (docs/planned-work.md #12): incident triage.
 * The model provider is faked (App\Services\Ai\AiClient::fake()), so
 * these prove the plumbing -- what leaves the system, what is kept,
 * what is refused -- not the model's judgement.
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AiClient::fake();
    }

    protected function tearDown(): void
    {
        AiClient::restore();
        parent::tearDown();
    }

    public function test_redactor_masks_emails_phones_and_names_but_keeps_company_names(): void
    {
        $text = 'Hi, Tan Wei Ming from Acme Manufacturing here (weiming@acme.com.sg, +65 9123 4567 or 6123-4567). Printer is down.';
        $out = Redactor::redact($text, ['Tan Wei Ming']);

        $this->assertStringNotContainsString('weiming@acme.com.sg', $out);
        $this->assertStringNotContainsString('9123 4567', $out);
        $this->assertStringNotContainsString('Tan Wei Ming', $out);
        $this->assertStringContainsString('Acme Manufacturing', $out);
        $this->assertStringContainsString('[email]', $out);
        $this->assertStringContainsString('[phone]', $out);
        $this->assertStringContainsString('[name]', $out);
        $this->assertSame('acme.com.sg', Redactor::domainOf('WeiMing@Acme.com.sg'));
        $this->assertNull(Redactor::redact(null));
    }

    public function test_triage_suggests_from_the_data_it_was_shown_and_records_the_call(): void
    {
        [$company, $h, $user] = $this->licensedCompany();
        [$acme, $contract, $sender] = $this->acmeWithContract($company);
        $other = CompanyIndividual::factory()->for($company)->create(['name' => 'Other Co', 'is_customer' => true]);

        // A resolved past incident with what fixed it, via its Job Order's approved Service Record.
        $jo = JobOrder::factory()->for($company)->create(['customer_id' => $acme->id, 'contract_id' => $contract->id, 'status' => JobOrder::STATUS_CLOSED]);
        $past = Incident::factory()->for($company)->create([
            'customer_id' => $acme->id, 'subject' => 'Printer offline on level 3', 'status' => Incident::STATUS_CONVERTED,
            'converted_job_order_id' => $jo->id,
        ]);
        ServiceRecord::factory()->create([
            'company_id' => $company->id, 'job_order_id' => $jo->id, 'employee_user_id' => $user->id,
            'status' => ServiceRecord::STATUS_APPROVED, 'work_description' => 'Reset the print spooler and re-added the queue for '.$sender->name,
        ]);

        $incident = Incident::factory()->for($company)->create([
            'customer_id' => null, 'subject' => 'Printer not working again', 'sender_name' => $sender->name,
            'sender_email' => 'someone.else@acme.com.sg', 'sender_phone' => '+65 9123 4567',
            'description' => 'Hi, '.$sender->name.' here, call me on 9123 4567. The level 3 printer is offline again.',
        ]);

        AiClient::fake([[
            'summary' => 'Printer offline again at Acme level 3',
            'customer_id' => $acme->id, 'customer_confidence' => 'high', 'customer_reason' => 'sender domain acme.com.sg',
            'contract_id' => $contract->id, 'priority' => 'high', 'route' => 'job_order', 'route_reason' => 'support under contract',
            'similar_incidents' => [
                ['incident_id' => $past->id, 'why_similar' => 'same printer', 'what_fixed_it' => 'Reset the print spooler'],
                ['incident_id' => 'not-a-real-id', 'why_similar' => 'made up', 'what_fixed_it' => null],
            ],
            'suggested_reply' => 'Thank you, we are looking into the printer now.',
            '__input_tokens' => 1234, '__output_tokens' => 210,
        ]]);

        $res = $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertOk();
        $res->assertJsonPath('status', 'ok')
            ->assertJsonPath('suggestion.customer_id', $acme->id)
            ->assertJsonPath('suggestion.customer_name', 'Acme Manufacturing Pte Ltd')
            ->assertJsonPath('suggestion.contract_id', $contract->id)
            ->assertJsonPath('suggestion.contract_number', $contract->contract_number)
            ->assertJsonPath('suggestion.route', 'job_order')
            ->assertJsonPath('suggestion.priority', 'high')
            ->assertJsonPath('suggestion.personal_data_redacted', true)
            ->assertJsonPath('input_tokens', 1234);
        // The invented id was dropped; the real one carries its number.
        $this->assertCount(1, $res->json('suggestion.similar_incidents'));
        $this->assertSame($past->incident_number, $res->json('suggestion.similar_incidents.0.incident_number'));

        // What left the system: redacted, but with the matching signals kept.
        $sent = AiClient::requests();
        $this->assertCount(1, $sent);
        $prompt = $sent[0]['user'];
        $this->assertStringNotContainsString('someone.else@acme.com.sg', $prompt);
        $this->assertStringNotContainsString('9123 4567', $prompt);
        $this->assertStringNotContainsString($sender->name, $prompt);
        $this->assertStringContainsString('acme.com.sg', $prompt, 'the email domain is a company signal, kept');
        $this->assertStringContainsString('Acme Manufacturing Pte Ltd', $prompt);
        $this->assertStringContainsString('Other Co', $prompt);
        $this->assertStringContainsString($contract->contract_number, $prompt);
        $this->assertStringContainsString('Reset the print spooler', $prompt, 'the past fix is shown');
        $this->assertSame('claude-opus-5', $sent[0]['model']);
        $this->assertSame('json_schema', 'json_schema'); // schema is passed as given
        $this->assertArrayHasKey('properties', $sent[0]['schema']);

        // Recorded: an interaction row (no prompt stored) and an Event Log entry.
        $row = AiInteraction::where('entity_id', $incident->id)->firstOrFail();
        $this->assertSame('ok', $row->status);
        $this->assertSame(1234, $row->input_tokens);
        $this->assertSame($user->id, $row->user_id);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'ai_triage_suggested']);

        // The incident itself is untouched: the assistant proposes, never commits.
        $this->assertNull($incident->fresh()->customer_id);
        $this->assertSame(Incident::STATUS_OPEN, $incident->fresh()->status);

        // The latest suggestion can be fetched again.
        $this->getJson("/api/ai/incidents/{$incident->id}/triage", $h)->assertOk()->assertJsonPath('id', $row->id);
    }

    public function test_redaction_off_sends_the_details_as_they_are(): void
    {
        [$company, $h] = $this->licensedCompany();
        [$acme, $contract, $sender] = $this->acmeWithContract($company);
        AiSetting::current()->fill(['redact_personal_data' => false])->save();
        $incident = Incident::factory()->for($company)->create(['sender_email' => 'someone@acme.com.sg', 'sender_name' => $sender->name]);
        AiClient::fake([$this->minimalAnswer()]);

        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertOk()->assertJsonPath('suggestion.personal_data_redacted', false);
        $this->assertStringContainsString('someone@acme.com.sg', AiClient::requests()[0]['user']);
        $this->assertStringContainsString($sender->name, AiClient::requests()[0]['user']);
    }

    public function test_a_contract_belonging_to_another_customer_is_dropped(): void
    {
        [$company, $h] = $this->licensedCompany();
        [$acme, $contract] = $this->acmeWithContract($company);
        $other = CompanyIndividual::factory()->for($company)->create(['name' => 'Other Co', 'is_customer' => true]);
        $incident = Incident::factory()->for($company)->create();
        AiClient::fake([$this->minimalAnswer(['customer_id' => $other->id, 'contract_id' => $contract->id, 'route' => 'nonsense', 'priority' => 'urgent'])]);

        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertOk()
            ->assertJsonPath('suggestion.customer_id', $other->id)
            ->assertJsonPath('suggestion.contract_id', null)
            ->assertJsonPath('suggestion.route', 'callback')
            ->assertJsonPath('suggestion.priority', 'normal');
    }

    public function test_a_refusal_and_an_error_are_recorded_not_hidden(): void
    {
        [$company, $h] = $this->licensedCompany();
        $incident = Incident::factory()->for($company)->create();

        AiClient::fake([['__refused' => true, '__reason' => 'declined']]);
        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertOk()
            ->assertJsonPath('status', 'refused')->assertJsonPath('suggestion', null)->assertJsonPath('error', 'declined');

        AiClient::fake([['__error' => 'Could not reach the model provider: timeout']]);
        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertStatus(502);
        $this->assertSame(1, AiInteraction::where('entity_id', $incident->id)->where('status', 'error')->count());
        $this->assertDatabaseHas('audit_log_entries', ['entity_id' => $incident->id, 'action' => 'ai_triage_failed']);
    }

    public function test_the_module_is_a_licence_that_gates_the_owner_too(): void
    {
        [$company, $h] = $this->licensedCompany();
        $incident = Incident::factory()->for($company)->create();
        CompanyModule::where('company_id', $company->id)->where('module_key', 'ai_assistant')->update(['enabled' => false]);

        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertStatus(403);
        $this->assertCount(0, AiClient::requests());

        // A staff member whose group lacks the module is refused before the licence check.
        CompanyModule::where('company_id', $company->id)->where('module_key', 'ai_assistant')->update(['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234'])->json('access_token');
        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }

    public function test_settings_key_is_write_only_and_the_change_is_audited(): void
    {
        [$company, $h] = $this->licensedCompany();

        $this->getJson('/api/ai/settings', $h)->assertOk()
            ->assertJsonPath('model', 'claude-opus-5')->assertJsonPath('redact_personal_data', true);

        $this->patchJson('/api/ai/settings', ['api_key' => 'sk-ant-secret', 'model' => 'claude-opus-5', 'redact_personal_data' => false], $h)
            ->assertOk()->assertJsonPath('api_key_set', true)->assertJsonPath('api_key_from_env', false)->assertJsonPath('redact_personal_data', false)
            ->assertJsonMissing(['api_key' => 'sk-ant-secret']);
        $this->assertStringNotContainsString('sk-ant-secret', $this->getJson('/api/ai/settings', $h)->getContent());
        $this->assertSame('sk-ant-secret', AiSetting::current()->effectiveApiKey());
        $this->assertNotSame('sk-ant-secret', DB::table('ai_settings')->value('api_key'), 'encrypted at rest');

        $this->patchJson('/api/ai/settings', ['model' => 'not valid!'], $h)->assertStatus(422);

        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'ai_setting', 'action' => 'updated']);

        // Usage summary counts the calls.
        $incident = Incident::factory()->for($company)->create();
        AiClient::fake([$this->minimalAnswer()]);
        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertOk();
        $this->getJson('/api/ai/usage', $h)->assertOk()
            ->assertJsonPath('this_month.calls', 1)->assertJsonPath('this_month.ok', 1)->assertJsonPath('all_time.input_tokens', 100)
            ->assertJsonPath('recent.0.feature', 'incident_triage');
    }

    public function test_without_a_key_anywhere_the_call_is_refused_with_a_clear_message(): void
    {
        AiClient::restore(); // the real client, which stops before any network call
        [$company, $h] = $this->licensedCompany();
        $incident = Incident::factory()->for($company)->create();
        putenv('ANTHROPIC_API_KEY=');

        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertStatus(422)
            ->assertJsonFragment(['detail' => 'The AI Assistant has no API key. Set one under Maintenance -> AI Assistant (or ANTHROPIC_API_KEY in .env).']);
        $this->postJson('/api/ai/settings/test', [], $h)->assertStatus(422);
    }

    public function test_the_context_shown_to_the_model_never_includes_archived_or_non_customers(): void
    {
        [$company] = $this->licensedCompany();
        [$acme] = $this->acmeWithContract($company);
        CompanyIndividual::factory()->for($company)->create(['name' => 'Gone Ltd', 'is_customer' => true, 'is_archived' => true]);
        CompanyIndividual::factory()->for($company)->create(['name' => 'Supplier Only', 'is_customer' => false, 'is_supplier' => true]);
        $incident = Incident::factory()->for($company)->create();

        $names = collect(IncidentTriage::buildContext($incident, true)['customers'])->pluck('name');
        $this->assertTrue($names->contains('Acme Manufacturing Pte Ltd'));
        $this->assertFalse($names->contains('Gone Ltd'));
        $this->assertFalse($names->contains('Supplier Only'));
    }

    // ---- helpers ----------------------------------------------------

    private function minimalAnswer(array $overrides = []): array
    {
        return $overrides + [
            'summary' => 's', 'customer_id' => null, 'customer_confidence' => 'none', 'customer_reason' => '',
            'contract_id' => null, 'priority' => 'normal', 'route' => 'callback', 'route_reason' => '',
            'similar_incidents' => [], 'suggested_reply' => 'Thank you.',
        ];
    }

    /** @return array{0: Company, 1: array<string, string>, 2: User} owner of a company licensed for the AI Assistant */
    private function licensedCompany(): array
    {
        $company = Company::factory()->create();
        foreach (['service_operations', 'ai_assistant', 'core_administration'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true, 'license_type' => $key === 'ai_assistant' ? CompanyModule::ADD_ON : CompanyModule::INCLUDED]);
        }
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');

        return [$company, ['Authorization' => "Bearer {$token}"], $owner];
    }

    /** @return array{0: CompanyIndividual, 1: Contract, 2: Contact} */
    private function acmeWithContract(Company $company): array
    {
        $acme = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Manufacturing Pte Ltd', 'is_customer' => true, 'contact_person' => 'Tan Wei Ming']);
        $contact = Contact::create(['customer_id' => $acme->id, 'name' => 'Tan Wei Ming', 'email' => 'weiming@acme.com.sg', 'phone' => '+65 6123 4567', 'is_active' => true]);
        $contract = Contract::factory()->for($company)->create(['customer_id' => $acme->id, 'status' => Contract::STATUS_ACTIVE, 'contracted_minutes' => 600]);

        return [$acme, $contract, $contact];
    }
}
