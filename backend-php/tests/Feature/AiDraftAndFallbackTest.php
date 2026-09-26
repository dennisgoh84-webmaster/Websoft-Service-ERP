<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog 2, AI Assistant (Dennis, 2026-09-26): "Draft with AI" by a
 * Service Record's work description, and the fallback model tried once
 * when the main one fails or declines.
 */
class AiDraftAndFallbackTest extends TestCase
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

    public function test_rough_notes_come_back_as_an_english_description_and_the_call_is_recorded(): void
    {
        [$company, $h] = $this->licensedCompany();
        $jo = JobOrder::factory()->create(['company_id' => $company->id]);
        AiClient::fake([['description' => "Replaced the faulty UPS battery in the server room.\n- Tested the UPS on battery for 5 minutes."]]);

        $this->postJson('/api/ai/draft-work-description', [
            'notes' => '换了server room UPS battery, test 5 min ok. call 9123 4567 / ops@acme.example if issue',
            'job_order_id' => $jo->id,
        ], $h)->assertOk()
            ->assertJsonPath('refused', false)
            ->assertJsonPath('description', "Replaced the faulty UPS battery in the server room.\n- Tested the UPS on battery for 5 minutes.");

        $sent = AiClient::requests()[0]['user'];
        $this->assertStringContainsString('换了server room UPS battery', $sent);
        $this->assertStringNotContainsString('ops@acme.example', $sent, 'emails are masked under redaction');
        $this->assertStringNotContainsString('9123 4567', $sent, 'phone numbers are masked under redaction');

        $i = AiInteraction::where('feature', AiInteraction::FEATURE_WORK_DESCRIPTION_DRAFT)->sole();
        $this->assertSame('ok', $i->status);
        $this->assertSame($jo->id, $i->entity_id);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'ai_interaction', 'entity_id' => $i->id, 'action' => 'ai_work_description_drafted']);
    }

    public function test_drafting_needs_notes_the_module_and_edit_on_service_operations(): void
    {
        [$company, $h] = $this->licensedCompany();
        $this->postJson('/api/ai/draft-work-description', ['notes' => '   '], $h)->assertStatus(422);
        $this->postJson('/api/ai/draft-work-description', ['notes' => 'x', 'job_order_id' => '00000000-0000-0000-0000-00000000abcd'], $h)->assertStatus(404);

        CompanyModule::where('company_id', $company->id)->where('module_key', 'ai_assistant')->update(['enabled' => false]);
        $this->postJson('/api/ai/draft-work-description', ['notes' => 'fixed printer'], $h)->assertStatus(403);
        $this->assertCount(0, AiClient::requests());
    }

    public function test_the_fallback_model_answers_when_the_main_one_fails(): void
    {
        [, $h] = $this->licensedCompany();
        AiSetting::current()->fill(['model' => 'main-model', 'fallback_model' => 'spare-model'])->save();
        AiClient::fake([['__error' => 'overloaded'], ['description' => 'Fixed the printer.']]);

        $this->postJson('/api/ai/draft-work-description', ['notes' => 'fix printer'], $h)->assertOk()
            ->assertJsonPath('description', 'Fixed the printer.')
            ->assertJsonPath('model', 'spare-model');
        $this->assertSame(['main-model', 'spare-model'], array_column(AiClient::requests(), 'model'));
    }

    public function test_the_fallback_model_is_tried_when_the_main_one_declines_and_its_tokens_count(): void
    {
        [, $h] = $this->licensedCompany();
        AiSetting::current()->fill(['model' => 'main-model', 'fallback_model' => 'spare-model'])->save();
        AiClient::fake([['__refused' => true], ['text' => 'Hello there', '__input_tokens' => 30, '__output_tokens' => 7]]);

        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]], $h)->assertOk()
            ->assertJsonPath('refused', false)
            ->assertJsonPath('answer', 'Hello there')
            ->assertJsonPath('model', 'spare-model');
        $this->assertSame(['main-model', 'spare-model'], array_column(AiClient::requests(), 'model'));
    }

    public function test_without_a_fallback_a_failure_is_reported_as_before_and_the_setting_is_saved_and_audited(): void
    {
        [, $h] = $this->licensedCompany();
        AiClient::fake([['__error' => 'overloaded']]);
        $this->postJson('/api/ai/draft-work-description', ['notes' => 'fix printer'], $h)->assertStatus(502);
        $this->assertCount(1, AiClient::requests());
        $this->assertSame('error', AiInteraction::where('feature', AiInteraction::FEATURE_WORK_DESCRIPTION_DRAFT)->value('status'));

        $this->patchJson('/api/ai/settings', ['fallback_model' => 'claude-sonnet-5'], $h)->assertOk()->assertJsonPath('fallback_model', 'claude-sonnet-5');
        $this->patchJson('/api/ai/settings', ['fallback_model' => 'Not A Model!'], $h)->assertStatus(422);
        $this->patchJson('/api/ai/settings', ['fallback_model' => ''], $h)->assertOk()->assertJsonPath('fallback_model', null);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'ai_setting', 'action' => 'updated']);
    }

    /** @return array{0: Company, 1: array<string, string>} */
    private function licensedCompany(): array
    {
        $company = Company::factory()->create();
        foreach (['service_operations', 'ai_assistant', 'core_administration'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true, 'license_type' => $key === 'ai_assistant' ? CompanyModule::ADD_ON : CompanyModule::INCLUDED]);
        }
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');

        return [$company, ['Authorization' => "Bearer {$token}"]];
    }
}
