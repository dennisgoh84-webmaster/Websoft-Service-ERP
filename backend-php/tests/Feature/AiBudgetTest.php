<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Incident;
use App\Models\ModuleCatalog;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiBudgetExceededException;
use App\Services\Ai\AiClient;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The AI Assistant's monthly token cap (decision 12.2, settled
 * 2026-09-16: "a spending cap... yes pls proceed to build in the
 * settings"). Measured in tokens, not SGD -- see
 * App\Services\Ai\AiBudget's docblock. Checked once per request, at
 * every entry point that can call the provider, so a capped account
 * gets a clean refusal rather than a conversation that dies mid-reply.
 */
class AiBudgetTest extends TestCase
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

    public function test_no_cap_set_is_unlimited_the_default_and_backward_compatible(): void
    {
        [$company] = $this->licensedCompany();
        $this->assertNull(AiBudget::capFor($company->id));
        $this->recordUsage($company, 1_000_000, 1_000_000);

        AiBudget::assertWithinCap($company->id); // does not throw
        $this->assertTrue(true);
    }

    public function test_under_the_cap_proceeds_and_at_or_over_it_is_refused(): void
    {
        [$company] = $this->licensedCompany();
        $company->forceFill(['ai_monthly_token_cap' => 1000])->save();
        $this->recordUsage($company, 400, 400);
        AiBudget::assertWithinCap($company->id); // 800 used of 1000: fine

        $this->recordUsage($company, 100, 100);
        // 1000 used of 1000: at the cap is already "reached"
        $this->expectException(AiBudgetExceededException::class);
        AiBudget::assertWithinCap($company->id);
    }

    public function test_the_boundary_is_the_same_singapore_calendar_month_the_usage_endpoint_reports(): void
    {
        [$company] = $this->licensedCompany();
        $company->forceFill(['ai_monthly_token_cap' => 100])->save();
        $lastMonth = Carbon::now()->startOfMonth()->subDay();
        $this->recordUsage($company, 100, 100, $lastMonth);

        // Last month's usage does not count toward this month's cap.
        $this->assertSame(0, AiBudget::tokensUsedThisMonth($company->id));
        AiBudget::assertWithinCap($company->id);
        $this->assertTrue(true);
    }

    public function test_incident_triage_is_refused_at_the_cap_with_no_provider_call_or_recorded_interaction(): void
    {
        [$company, $h] = $this->licensedCompany();
        $incident = Incident::factory()->for($company)->create();
        $company->forceFill(['ai_monthly_token_cap' => 10])->save();
        $this->recordUsage($company, 5, 5);

        $this->postJson("/api/ai/incidents/{$incident->id}/triage", [], $h)->assertStatus(422)
            ->assertJsonFragment(['detail' => "This company's monthly AI token cap (10) has been reached (10 used so far this month) -- no further calls will be made until next month, or the cap is raised under Maintenance -> AI Assistant."]);
        $this->assertCount(0, AiClient::requests());
        $this->assertSame(1, AiInteraction::count(), 'no new interaction row for a capped call');
    }

    public function test_staff_chat_is_refused_at_the_cap(): void
    {
        [$company, $h] = $this->licensedCompany();
        $company->forceFill(['ai_monthly_token_cap' => 10])->save();
        $this->recordUsage($company, 10, 0);

        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]], $h)->assertStatus(422);
        $this->assertCount(0, AiClient::requests());
    }

    public function test_test_connection_is_refused_at_the_cap(): void
    {
        [$company, $h] = $this->licensedCompany();
        $company->forceFill(['ai_monthly_token_cap' => 10])->save();
        $this->recordUsage($company, 10, 0);

        $this->postJson('/api/ai/settings/test', [], $h)->assertStatus(422);
        $this->assertCount(0, AiClient::requests());
    }

    public function test_portal_chat_is_refused_at_the_cap(): void
    {
        $company = Company::factory()->create();
        foreach (['ai_assistant'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true, 'license_type' => CompanyModule::ADD_ON]);
        }
        $customer = CompanyIndividual::factory()->for($company)->create(['pdpa_consent_given' => true]);
        $contact = Contact::create(['customer_id' => $customer->id, 'name' => 'Alice Tan', 'email' => 'alice@example.com', 'phone' => '+65 9000 0001']);
        PortalUser::create([
            'company_id' => $company->id, 'contact_id' => $contact->id, 'email' => $contact->email,
            'hashed_password' => PasswordPolicy::hash('portal123'), 'must_change_password' => false,
        ]);
        $token = $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => 'portal123'])->json('portal_token');
        $this->postJson('/api/portal/ai/consent', ['accepted' => true], ['Authorization' => "Bearer {$token}"])->assertOk();

        $company->forceFill(['ai_monthly_token_cap' => 10])->save();
        $this->recordUsage($company, 10, 0);

        $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422);
        $this->assertCount(0, AiClient::requests());
    }

    public function test_the_cap_is_settable_and_readable_via_settings_and_a_removed_cap_is_unlimited_again(): void
    {
        [$company, $h] = $this->licensedCompany();
        $this->getJson('/api/ai/settings', $h)->assertOk()->assertJsonPath('monthly_token_cap', null);

        $this->patchJson('/api/ai/settings', ['monthly_token_cap' => 50000], $h)->assertOk()->assertJsonPath('monthly_token_cap', 50000);
        $this->patchJson('/api/ai/settings', ['monthly_token_cap' => 0], $h)->assertStatus(422);
        $this->patchJson('/api/ai/settings', ['monthly_token_cap' => -1], $h)->assertStatus(422);

        $this->patchJson('/api/ai/settings', ['monthly_token_cap' => null], $h)->assertOk()->assertJsonPath('monthly_token_cap', null);
        $this->recordUsage($company, 1_000_000, 0);
        AiClient::fake([['ok' => true, 'greeting' => 'hi']]);
        $this->postJson('/api/ai/settings/test', [], $h)->assertOk();
    }

    public function test_each_company_counts_against_its_own_cap_and_settings_show_the_installation_total(): void
    {
        [$company, $h] = $this->licensedCompany();
        [$other] = $this->licensedCompany();
        $company->forceFill(['ai_monthly_token_cap' => 1000])->save();
        $this->recordUsage($other, 5000, 0); // another company's use does not count here
        $this->recordUsage($company, 300, 0);
        AiBudget::assertWithinCap($company->id);
        AiBudget::assertWithinCap($other->id); // no cap on the other company

        $this->getJson('/api/ai/settings', $h)->assertOk()
            ->assertJsonPath('monthly_token_cap', 1000)
            ->assertJsonPath('monthly_tokens_used', 300)
            ->assertJsonPath('install_monthly_tokens_used', 5300);

        $this->patchJson('/api/ai/settings', ['monthly_token_cap' => 250], $h)->assertOk();
        $this->assertSame(250, $company->fresh()->ai_monthly_token_cap);
        $this->assertNull($other->fresh()->ai_monthly_token_cap, 'setting one company\'s cap leaves the others alone');
        $this->expectException(AiBudgetExceededException::class);
        AiBudget::assertWithinCap($company->id);
    }

    // ---- helpers ----------------------------------------------------

    private function recordUsage(Company $company, int $inputTokens, int $outputTokens, ?Carbon $createdAt = null): AiInteraction
    {
        return AiInteraction::create([
            'company_id' => $company->id,
            'feature' => AiInteraction::FEATURE_CONNECTION_TEST,
            'model' => 'claude-opus-5',
            'status' => AiInteraction::STATUS_OK,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'created_at' => $createdAt ?? Carbon::now(),
        ]);
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
