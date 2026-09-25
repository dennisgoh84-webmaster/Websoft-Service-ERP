<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\Prospect;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Prospect Activities -- each logged against a Prospect. Formerly the
 * "CRM" activities, which hung off the Company / Individual directly.
 */
class ProspectActivityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'prospects'], ['name' => 'Prospect / Leads', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => 'prospects'],
            ['enabled' => true, 'license_type' => CompanyModule::INCLUDED],
        );
        $this->group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create(['group_id' => $this->group->id, 'module_key' => 'prospects', 'access_level' => GroupModuleAuthority::EDIT]);
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function login(string $role, array $attrs = []): array
    {
        $user = User::factory()->for($this->company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')] + $attrs);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $this->group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    private function prospectFor(User $salesperson, ?CompanyIndividual $customer = null): Prospect
    {
        $customer ??= CompanyIndividual::factory()->for($this->company)->create();

        return Prospect::factory()->create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'salesperson_user_id' => $salesperson->id, 'created_by_user_id' => $salesperson->id,
        ]);
    }

    private function log(array $h, Prospect $p, string $type, string $subject)
    {
        return $this->postJson('/api/prospect-activities', ['prospect_id' => $p->id, 'activity_type' => $type, 'subject' => $subject], $h);
    }

    public function test_logging_an_activity_names_its_prospect_company_and_creator(): void
    {
        [$owner, $h] = $this->login(User::ROLE_OWNER, ['full_name' => 'Dennis Owner']);
        $p = $this->prospectFor($owner);

        $this->log($h, $p, 'call', 'Initial call with prospect')->assertOk()->assertJson([
            'prospect_id' => $p->id, 'prospect_number' => $p->prospect_number, 'customer_id' => $p->customer_id,
            'activity_type' => 'call', 'status' => 'completed', 'created_by_name' => 'Dennis Owner',
        ]);
        $this->getJson('/api/prospect-activities', $h)->assertOk()->assertJsonCount(1);
    }

    public function test_times_are_the_real_moment_not_eight_hours_off(): void
    {
        // The app runs in Asia/Singapore against UTC columns: an activity
        // logged on the go (no date given) and the prospect it sits under
        // must both report now, not now +/- 8 hours.
        [$owner, $h] = $this->login(User::ROLE_OWNER);
        $prospect = $this->postJson('/api/prospects', ['customer_id' => CompanyIndividual::factory()->for($this->company)->create()->id, 'title' => 'x'], $h)->json();
        $activity = $this->postJson('/api/prospect-activities', ['prospect_id' => $prospect['id'], 'activity_type' => 'call', 'subject' => 'On the go'], $h)->json();

        foreach (['prospect created_at' => $prospect['created_at'], 'activity created_at' => $activity['created_at'], 'activity date' => $activity['activity_date']] as $what => $value) {
            $this->assertEqualsWithDelta(time(), Carbon::parse($value)->getTimestamp(), 120, "{$what} is the real moment");
        }
    }

    public function test_staff_see_only_activities_on_their_own_prospects(): void
    {
        [$owner, $ownerH] = $this->login(User::ROLE_OWNER);
        [$staff, $staffH] = $this->login(User::ROLE_SALES_STAFF);
        $this->log($ownerH, $this->prospectFor($owner), 'email', 'Owner activity')->assertOk();
        $this->log($staffH, $this->prospectFor($staff), 'call', 'Staff activity')->assertOk();

        $this->getJson('/api/prospect-activities', $ownerH)->assertOk()->assertJsonCount(2);
        $this->getJson('/api/prospect-activities', $staffH)->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Staff activity']);
    }

    public function test_staff_can_update_and_void_their_own_activities_but_not_others(): void
    {
        [$owner, $ownerH] = $this->login(User::ROLE_OWNER);
        [$staff, $staffH] = $this->login(User::ROLE_SALES_STAFF);
        $mine = $this->log($staffH, $this->prospectFor($staff), 'meeting', 'Initial meeting')->json('id');
        $theirs = $this->log($ownerH, $this->prospectFor($owner), 'note', 'Owner note')->json('id');

        $this->patchJson("/api/prospect-activities/{$mine}", ['subject' => 'Updated meeting notes'], $staffH)
            ->assertOk()->assertJson(['subject' => 'Updated meeting notes']);
        $this->patchJson("/api/prospect-activities/{$theirs}", ['subject' => 'Hacked!'], $staffH)->assertStatus(404);

        $this->postJson("/api/prospect-activities/{$theirs}/void", ['reason' => 'x'], $staffH)->assertStatus(404);
        $this->postJson("/api/prospect-activities/{$mine}/void", ['reason' => 'Logged against the wrong prospect'], $staffH)
            ->assertOk()->assertJson(['status' => 'void', 'void_reason' => 'Logged against the wrong prospect', 'voided_by_name' => $staff->full_name]);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'prospect_activity', 'entity_id' => $mine, 'action' => 'voided']);
    }

    public function test_an_activity_is_never_deleted_only_voided_and_a_void_one_is_frozen(): void
    {
        [$owner, $h] = $this->login(User::ROLE_OWNER);
        $id = $this->log($h, $this->prospectFor($owner), 'call', 'Wrong number')->json('id');

        // No delete route at all.
        $this->deleteJson("/api/prospect-activities/{$id}", [], $h)->assertStatus(405);
        // VOID is only reached through the Void action, and it needs a reason.
        $this->patchJson("/api/prospect-activities/{$id}", ['status' => 'void'], $h)->assertStatus(422);
        $this->postJson("/api/prospect-activities/{$id}/void", [], $h)->assertStatus(422);
        $this->postJson("/api/prospect-activities/{$id}/void", ['reason' => 'Duplicate'], $h)->assertOk();

        // Still there, still listed -- as VOID -- and no longer editable.
        $this->assertDatabaseHas('prospect_activities', ['id' => $id, 'status' => 'void', 'void_reason' => 'Duplicate']);
        $this->getJson('/api/prospect-activities?status=void', $h)->assertOk()->assertJsonCount(1);
        $this->patchJson("/api/prospect-activities/{$id}", ['subject' => 'Changed'], $h)->assertStatus(409);
        $this->postJson("/api/prospect-activities/{$id}/void", ['reason' => 'Again'], $h)->assertStatus(409);
    }

    public function test_activity_types_are_validated(): void
    {
        [$owner, $h] = $this->login(User::ROLE_OWNER);
        $this->log($h, $this->prospectFor($owner), 'invalid_type', 'Test')->assertStatus(422);
    }

    public function test_can_filter_by_prospect_customer_and_activity_type(): void
    {
        [$owner, $h] = $this->login(User::ROLE_OWNER);
        $a = $this->prospectFor($owner, CompanyIndividual::factory()->for($this->company)->create(['name' => 'Customer A']));
        $b = $this->prospectFor($owner, CompanyIndividual::factory()->for($this->company)->create(['name' => 'Customer B']));
        $this->log($h, $a, 'call', 'Call with A')->assertOk();
        $this->log($h, $b, 'email', 'Email with B')->assertOk();

        $this->getJson("/api/prospect-activities?prospect_id={$a->id}", $h)->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Call with A']);
        $this->getJson("/api/prospect-activities?customer_id={$b->customer_id}", $h)->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Email with B']);
        $this->getJson('/api/prospect-activities?activity_type=email', $h)->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Email with B']);
    }
}
