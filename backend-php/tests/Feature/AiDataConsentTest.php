<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PDPA self-declaration for the AI Assistant (Dennis, 2026-09-15):
 * "no issues" sending masked text to Anthropic's US-hosted API,
 * PROVIDED every staff member has explicitly agreed to it -- once,
 * at login, permanently recorded, and never editable from Staff
 * Master or anywhere else.
 */
class AiDataConsentTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): array
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'must_change_password' => false,
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return [$owner, $login->json('access_token')];
    }

    public function test_login_and_me_report_consent_required_until_acknowledged(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);

        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);
        $login->assertOk()->assertJsonPath('ai_data_consent_required', true);

        $this->getJson('/api/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonPath('ai_data_consent_required', true);

        $this->assertNull($owner->fresh()->ai_data_consent_at);
    }

    public function test_acknowledging_records_it_once_and_it_then_reports_satisfied(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $h = ['Authorization' => "Bearer {$token}"];

        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00', 'UTC'));
        $res = $this->postJson('/api/auth/ai-consent', ['accepted' => true], $h);
        $res->assertOk()->assertJsonPath('ai_data_consent_required', false)
            ->assertJsonPath('ai_data_consent_at', '2026-09-16T09:00:00.000000Z');

        $this->assertNotNull($owner->fresh()->ai_data_consent_at);
        $this->getJson('/api/auth/me', $h)->assertOk()->assertJsonPath('ai_data_consent_required', false);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'user', 'entity_id' => $owner->id, 'action' => 'ai_data_consent_acknowledged',
        ]);
        Carbon::setTestNow();
    }

    public function test_unchecking_the_box_is_rejected_and_nothing_is_recorded(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $h = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/auth/ai-consent', ['accepted' => false], $h)->assertStatus(422);
        $this->postJson('/api/auth/ai-consent', [], $h)->assertStatus(422);
        $this->assertNull($owner->fresh()->ai_data_consent_at);
    }

    public function test_the_timestamp_can_never_be_moved_once_set(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $h = ['Authorization' => "Bearer {$token}"];

        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00', 'UTC'));
        $this->postJson('/api/auth/ai-consent', ['accepted' => true], $h)->assertOk();
        $first = $owner->fresh()->ai_data_consent_at;

        // A second acknowledgement, even much later, changes nothing.
        Carbon::setTestNow(Carbon::parse('2026-12-25 09:00:00', 'UTC'));
        $this->postJson('/api/auth/ai-consent', ['accepted' => true], $h)
            ->assertOk()->assertJsonPath('ai_data_consent_at', $first->toJSON());
        $this->assertTrue($owner->fresh()->ai_data_consent_at->equalTo($first));
        Carbon::setTestNow();
    }

    public function test_staff_master_shows_it_read_only_and_the_update_endpoint_cannot_touch_it(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $h = ['Authorization' => "Bearer {$token}"];
        foreach (['core_administration'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true]);
        }

        $this->getJson("/api/users/{$owner->id}", $h)->assertOk()->assertJsonPath('ai_data_consent_at', null);

        $this->postJson('/api/auth/ai-consent', ['accepted' => true], $h)->assertOk();
        $recorded = $owner->fresh()->ai_data_consent_at;
        $this->getJson("/api/users/{$owner->id}", $h)->assertOk()->assertJsonPath('ai_data_consent_at', $recorded->toJSON());

        // Even an explicit attempt to smuggle it through the ordinary
        // profile-update endpoint is silently ignored -- the field is
        // not in that endpoint's validated list at all.
        $this->patchJson("/api/users/{$owner->id}", ['ai_data_consent_at' => null, 'full_name' => 'Renamed Owner'], $h)
            ->assertOk()->assertJsonPath('ai_data_consent_at', $recorded->toJSON())->assertJsonPath('full_name', 'Renamed Owner');
        $this->assertTrue($owner->fresh()->ai_data_consent_at->equalTo($recorded));
    }

    public function test_a_second_staff_member_is_independently_gated(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_operations'], ['name' => 'service_operations', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'service_operations'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'), 'must_change_password' => false,
        ]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        [$owner] = $this->ownerToken($company);

        // The owner acknowledges; the second staff member is unaffected.
        $ownerLogin = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);
        $this->postJson('/api/auth/ai-consent', ['accepted' => true], ['Authorization' => 'Bearer '.$ownerLogin->json('access_token')])->assertOk();

        $staffLogin = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $staffLogin->assertOk()->assertJsonPath('ai_data_consent_required', true);
        $this->assertNull($staff->fresh()->ai_data_consent_at);
    }
}
