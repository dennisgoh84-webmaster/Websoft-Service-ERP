<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\EventLogController -- converted from
 * backend/app/routers/event_logs.py.
 */
class EventLogTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'event_logs';

    private function enableModule(Company $company, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Event Logs', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company);

        return $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function entry(array $attrs = []): AuditLogEntry
    {
        $at = $attrs['at'] ?? Carbon::now();
        unset($attrs['at']);

        $entry = AuditLogEntry::create(array_merge([
            'entity_type' => 'invoice',
            'entity_id' => (string) Str::uuid(),
            'action' => 'created',
        ], $attrs));

        // `at` is not fillable (the column defaults to now()), so a
        // test that needs a specific timestamp has to set it after.
        $entry->forceFill(['at' => $at])->save();

        return $entry->refresh();
    }

    public function test_entries_are_newest_first_and_scoped_to_the_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->entry(['company_id' => $company->id, 'details' => 'older', 'at' => Carbon::now()->subDay()]);
        $this->entry(['company_id' => $company->id, 'details' => 'newer']);
        $this->entry(['company_id' => $other->id, 'details' => 'theirs']);

        $body = $this->getJson('/api/event-logs', $this->headers($token))->assertOk()->json();
        // The login itself writes entries, so filter to the ones seeded here.
        $details = array_values(array_filter(array_column($body, 'details')));
        $this->assertContains('newer', $details);
        $this->assertContains('older', $details);
        $this->assertNotContains('theirs', $details, "another company's trail must not be visible");
        $this->assertLessThan(
            array_search('older', $details, true),
            array_search('newer', $details, true),
            'newest first',
        );
    }

    public function test_entries_with_no_company_are_visible_to_everyone(): void
    {
        // Deliberate: entries written before company stamping existed
        // carry a null company_id. Hiding them would silently shorten
        // an audit trail.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->entry(['company_id' => null, 'details' => 'pre-stamping entry']);

        $body = $this->getJson('/api/event-logs', $this->headers($token))->assertOk()->json();
        $this->assertContains('pre-stamping entry', array_column($body, 'details'));
    }

    public function test_filters_by_entity_type_action_and_free_text(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->entry(['company_id' => $company->id, 'entity_type' => 'contract', 'action' => 'activated', 'details' => 'CON-2026-0001']);
        $this->entry(['company_id' => $company->id, 'entity_type' => 'invoice', 'action' => 'issued', 'details' => 'BILL-001']);

        $this->getJson('/api/event-logs?entity_type=contract', $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.details', 'CON-2026-0001');
        $this->getJson('/api/event-logs?action=issued', $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.details', 'BILL-001');
        // Free text searches details, reason, actor, entity_type and action.
        $this->getJson('/api/event-logs?q=CON-2026', $this->headers($token))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.entity_type', 'contract');
    }

    public function test_date_to_is_inclusive_of_that_whole_day(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $day = Carbon::parse('2026-06-10');
        $this->entry(['company_id' => $company->id, 'details' => 'late that day', 'at' => $day->copy()->setTime(23, 59)]);
        $this->entry(['company_id' => $company->id, 'details' => 'next morning', 'at' => $day->copy()->addDay()->setTime(0, 5)]);

        // Python compares against the START of the following day, so an
        // entry at 23:59 on date_to is included.
        $body = $this->getJson('/api/event-logs?date_from=2026-06-10&date_to=2026-06-10', $this->headers($token))
            ->assertOk()->json();
        $details = array_column($body, 'details');
        $this->assertContains('late that day', $details);
        $this->assertNotContains('next morning', $details);
    }

    public function test_limit_is_capped_at_five_hundred_and_offset_pages(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        for ($i = 0; $i < 5; $i++) {
            $this->entry(['company_id' => $company->id, 'details' => "row {$i}", 'at' => Carbon::now()->subMinutes($i)]);
        }

        $this->getJson('/api/event-logs?limit=2', $this->headers($token))->assertOk()->assertJsonCount(2);
        // An absurd limit is clamped rather than refused.
        $this->getJson('/api/event-logs?limit=99999', $this->headers($token))->assertOk();

        $firstPage = $this->getJson('/api/event-logs?limit=1&offset=0', $this->headers($token))->json();
        $secondPage = $this->getJson('/api/event-logs?limit=1&offset=1', $this->headers($token))->json();
        $this->assertNotSame($firstPage[0]['id'], $secondPage[0]['id']);
    }

    public function test_exporting_the_trail_is_itself_recorded(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->entry(['company_id' => $company->id, 'details' => 'something happened']);

        $csv = $this->get('/api/event-logs/export.csv?entity_type=invoice', $this->headers($token))
            ->assertOk()->getContent();
        $this->assertStringContainsString('when,actor,action,entity_type', $csv);
        $this->assertStringContainsString('something happened', $csv);

        // Bulk-reading the audit trail would otherwise be the one action
        // it does not record.
        $export = AuditLogEntry::where('action', 'report_generated')->latest('at')->firstOrFail();
        $this->assertStringContainsString('Event Log CSV export', (string) $export->details);
        $this->assertStringContainsString('entity_type=invoice', (string) $export->details);
    }

    public function test_xlsx_export_is_a_real_package(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->entry(['company_id' => $company->id, 'details' => 'exported row']);

        $response = $this->get('/api/event-logs/export.xlsx', $this->headers($token))->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=event-logs.xlsx');
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
    }

    public function test_the_log_is_read_only(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $entry = $this->entry(['company_id' => $company->id]);

        // No write route exists at all -- that is what makes the trail
        // worth having. Laravel answers 405 where the path exists for
        // another verb and 404 where it does not; the meaningful claim
        // is that neither succeeds, so both are accepted here rather
        // than pinning a framework detail.
        foreach ([
            $this->postJson('/api/event-logs', ['details' => 'forged'], $this->headers($token)),
            $this->patchJson("/api/event-logs/{$entry->id}", ['details' => 'edited'], $this->headers($token)),
            $this->deleteJson("/api/event-logs/{$entry->id}", [], $this->headers($token)),
        ] as $response) {
            $this->assertContains($response->getStatusCode(), [404, 405]);
        }
        $this->assertSame(1, AuditLogEntry::whereKey($entry->id)->count());
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $this->enableModule($company, false);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::FULL,
        ]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->getJson('/api/event-logs', $this->headers($token))->assertStatus(403);
    }
}
