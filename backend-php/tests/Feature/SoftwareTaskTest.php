<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Incident;
use App\Models\ModuleCatalog;
use App\Models\SoftwareTask;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\SoftwareTaskController -- converted
 * from backend/app/routers/software_tasks.py -- and the Incidents
 * convert-to-software-task route that depended on it.
 */
class SoftwareTaskTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'software_development';

    private function enableModule(Company $company, string $module, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => $module], ['name' => $module, 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => $module],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company, self::MODULE);
        $this->enableModule($company, 'helpdesk');

        return $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_create_list_and_the_untested_only_filter(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $programmer = User::factory()->for($company)->create(['full_name' => 'Bob Lim']);

        $this->postJson('/api/software-tasks', [
            'title' => 'Fix the aging report',
            'modules_affected' => 'AR, Reports',
            'assigned_programmer_id' => $programmer->id,
            'programming_hours' => 4.5,
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('title', 'Fix the aging report')
            ->assertJsonPath('is_tested', false)
            ->assertJsonPath('programming_hours', 4.5);

        SoftwareTask::create([
            'company_id' => $company->id, 'title' => 'Already done', 'is_tested' => true,
        ]);

        $this->getJson('/api/software-tasks', $this->headers($token))->assertOk()->assertJsonCount(2);
        $this->getJson('/api/software-tasks?untested_only=true', $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Fix the aging report');
    }

    public function test_mark_tested_then_reopen_testing(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $task = SoftwareTask::create(['company_id' => $company->id, 'title' => 'A task']);

        $this->postJson("/api/software-tasks/{$task->id}/mark-tested", [], $this->headers($token))
            ->assertOk()->assertJsonPath('is_tested', true);
        $this->assertNotNull($task->fresh()->tested_at);

        $this->postJson("/api/software-tasks/{$task->id}/reopen-testing", [], $this->headers($token))
            ->assertOk()->assertJsonPath('is_tested', false)->assertJsonPath('tested_at', null);
        $this->assertNull($task->fresh()->tested_at);
    }

    public function test_update_records_only_changed_fields(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $task = SoftwareTask::create(['company_id' => $company->id, 'title' => 'Original', 'modules_affected' => 'AR']);

        $this->patchJson("/api/software-tasks/{$task->id}", [
            'title' => 'Original', 'modules_affected' => 'AR, AP',
        ], $this->headers($token))->assertOk();

        $entry = AuditLogEntry::where('entity_type', 'software_task')
            ->where('action', 'updated')->firstOrFail();
        $newValue = json_decode((string) $entry->new_value, true);
        $this->assertArrayHasKey('modules_affected', $newValue);
        $this->assertArrayNotHasKey('title', $newValue, 'an unchanged field must not be recorded');
    }

    public function test_a_programmer_from_another_company_is_refused(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirUser = User::factory()->for($other)->create();

        // Python passes these ids straight through; refusing a foreign
        // one is deliberate hardening, recorded in the conversion plan.
        $this->postJson('/api/software-tasks', [
            'title' => 'Cross-company', 'assigned_programmer_id' => $theirUser->id,
        ], $this->headers($token))->assertStatus(404);
    }

    public function test_another_companys_task_is_not_found(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $theirs = SoftwareTask::create(['company_id' => $other->id, 'title' => 'Theirs']);

        $this->patchJson("/api/software-tasks/{$theirs->id}", ['title' => 'Hijacked'], $this->headers($token))
            ->assertStatus(404);
        $this->postJson("/api/software-tasks/{$theirs->id}/mark-tested", [], $this->headers($token))
            ->assertStatus(404);
    }

    public function test_csv_export_resolves_programmer_and_tester_names(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $programmer = User::factory()->for($company)->create(['full_name' => 'Bob Lim']);
        $tester = User::factory()->for($company)->create(['full_name' => 'Cara Ng']);
        SoftwareTask::create([
            'company_id' => $company->id, 'title' => 'Named task',
            'assigned_programmer_id' => $programmer->id, 'tester_user_id' => $tester->id,
        ]);

        $csv = $this->get('/api/software-tasks/export.csv', $this->headers($token))->assertOk()->getContent();
        $this->assertStringContainsString('title,modules_affected,assigned_programmer', $csv);
        $this->assertStringContainsString('Bob Lim', $csv);
        $this->assertStringContainsString('Cara Ng', $csv);
    }

    public function test_view_level_cannot_create(): void
    {
        $company = Company::factory()->create();
        $this->enableModule($company, self::MODULE);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::VIEW,
        ]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->getJson('/api/software-tasks', $this->headers($token))->assertOk();
        $this->postJson('/api/software-tasks', ['title' => 'No'], $this->headers($token))->assertStatus(403);
    }

    // ── The Incidents route that depended on this module ────────────

    public function test_an_incident_converts_to_a_software_task(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $programmer = User::factory()->for($company)->create(['full_name' => 'Bob Lim']);
        $incident = Incident::create([
            'company_id' => $company->id,
            'incident_number' => 'INC-2026-0001',
            'subject' => 'Report crashes on export',
            'description' => 'Steps to reproduce...',
            'source' => 'email',
        ]);

        $task = $this->postJson("/api/incidents/{$incident->id}/convert-to-software-task", [
            'assigned_programmer_id' => $programmer->id,
        ], $this->headers($token))->assertOk()->json();

        // The incident's subject and description carry across, not just
        // a routing flag -- a real task is created and linked back.
        $this->assertSame('Report crashes on export', $task['title']);
        $this->assertSame('Steps to reproduce...', $task['description']);
        $this->assertSame($programmer->id, $task['assigned_programmer_id']);

        $incident->refresh();
        $this->assertSame(Incident::STATUS_CONVERTED, $incident->status);
        $this->assertSame($task['id'], $incident->converted_software_task_id);
    }

    public function test_converting_needs_no_customer_or_contract(): void
    {
        // Unlike the Quotation and Job Order routes: a bug report is a
        // bug report whether or not the caller was ever identified.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $incident = Incident::create([
            'company_id' => $company->id, 'incident_number' => 'INC-2026-0002',
            'subject' => 'Anonymous bug', 'source' => 'phone',
        ]);

        $this->postJson("/api/incidents/{$incident->id}/convert-to-software-task", [], $this->headers($token))
            ->assertOk()->assertJsonPath('title', 'Anonymous bug');
    }

    public function test_an_already_converted_incident_cannot_be_converted_again(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $incident = Incident::create([
            'company_id' => $company->id, 'incident_number' => 'INC-2026-0003',
            'subject' => 'Once only', 'source' => 'phone',
        ]);

        $this->postJson("/api/incidents/{$incident->id}/convert-to-software-task", [], $this->headers($token))->assertOk();
        $this->postJson("/api/incidents/{$incident->id}/convert-to-software-task", [], $this->headers($token))
            ->assertStatus(422);
    }
}
