<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmProspectActivityTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'crm';

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function staffToken(Company $company): string
    {
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function setupGroupAuthority(Company $company, string $accessLevel = 'edit'): Group
    {
        // Ensure the module exists in the catalog
        ModuleCatalog::firstOrCreate(
            ['key' => self::MODULE],
            ['name' => 'CRM', 'is_built' => true],
        );

        // Enable the module for the company
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => true, 'license_type' => CompanyModule::ADD_ON],
        );

        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id,
            'module_key' => self::MODULE,
            'access_level' => $accessLevel,
        ]);

        return $group;
    }

    public function test_owner_can_create_and_list_prospect_activities(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $create = $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'call',
                'subject' => 'Initial call with prospect',
                'description' => 'Discussed their IT infrastructure needs',
                'status' => 'completed',
            ],
            $this->headers($token),
        );

        $create->assertOk()
            ->assertJson([
                'subject' => 'Initial call with prospect',
                'activity_type' => 'call',
                'status' => 'completed',
            ]);

        $list = $this->getJson('/api/crm/activities', $this->headers($token));
        $list->assertOk()->assertJsonCount(1);
    }

    public function test_sales_staff_can_only_see_their_own_activities(): void
    {
        $company = Company::factory()->create();
        $group = $this->setupGroupAuthority($company, 'edit');
        $ownerToken = $this->ownerToken($company);

        // Create staff user with group access
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        UserCompanyAccess::create([
            'user_id' => $staff->id,
            'company_id' => $company->id,
            'group_id' => $group->id,
        ]);
        $staff->update(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $staffToken = $login->json('access_token');

        $customer = CompanyIndividual::factory()->for($company)->create();

        // Owner creates an activity
        $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'email',
                'subject' => 'Owner activity',
            ],
            $this->headers($ownerToken),
        )->assertOk();

        // Staff creates an activity
        $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'call',
                'subject' => 'Staff activity',
            ],
            $this->headers($staffToken),
        )->assertOk();

        // Owner sees both activities
        $ownerList = $this->getJson('/api/crm/activities', $this->headers($ownerToken));
        $ownerList->assertOk()->assertJsonCount(2);

        // Staff sees only their own activity
        $staffList = $this->getJson('/api/crm/activities', $this->headers($staffToken));
        $staffList->assertOk()->assertJsonCount(1);
        $staffList->assertJsonFragment(['subject' => 'Staff activity']);
    }

    public function test_mobile_crm_list_exists_and_is_scoped_like_the_desktop_one(): void
    {
        $company = Company::factory()->create();
        $group = $this->setupGroupAuthority($company, 'edit');
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER, 'full_name' => 'Dennis Owner', 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $ownerToken = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $staffToken = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234'])->json('access_token');
        $customer = CompanyIndividual::factory()->for($company)->create();

        // The desktop response names who created it (it used to be blank).
        $this->postJson('/api/crm/activities', ['customer_id' => $customer->id, 'activity_type' => 'email', 'subject' => 'Owner activity'], $this->headers($ownerToken))
            ->assertOk()->assertJson(['created_by_name' => 'Dennis Owner']);
        $this->postJson('/api/crm/activities', ['customer_id' => $customer->id, 'activity_type' => 'call', 'subject' => 'Staff activity'], $this->headers($staffToken))
            ->assertOk();

        $this->getJson('/api/mobile/crm/activities', $this->headers($ownerToken))
            ->assertOk()->assertJsonCount(2)->assertJsonFragment(['subject' => 'Owner activity', 'created_by_name' => 'Dennis Owner']);
        $this->getJson('/api/mobile/crm/activities', $this->headers($staffToken))
            ->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Staff activity']);
    }

    public function test_staff_can_update_their_own_activities(): void
    {
        $company = Company::factory()->create();
        $group = $this->setupGroupAuthority($company, 'edit');

        // Create staff user with group access
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        UserCompanyAccess::create([
            'user_id' => $staff->id,
            'company_id' => $company->id,
            'group_id' => $group->id,
        ]);
        $staff->update(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $customer = CompanyIndividual::factory()->for($company)->create();

        $create = $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'meeting',
                'subject' => 'Initial meeting',
            ],
            $this->headers($token),
        );

        $activityId = $create->json('id');

        $update = $this->patchJson(
            "/api/crm/activities/{$activityId}",
            [
                'subject' => 'Updated meeting notes',
                'status' => 'completed',
            ],
            $this->headers($token),
        );

        $update->assertOk()->assertJson(['subject' => 'Updated meeting notes']);
    }

    public function test_staff_cannot_update_others_activities(): void
    {
        $company = Company::factory()->create();
        $ownerToken = $this->ownerToken($company);
        $staffToken = $this->staffToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $create = $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'note',
                'subject' => 'Owner note',
            ],
            $this->headers($ownerToken),
        );

        $activityId = $create->json('id');

        $update = $this->patchJson(
            "/api/crm/activities/{$activityId}",
            ['subject' => 'Hacked!'],
            $this->headers($staffToken),
        );

        $update->assertStatus(403);
    }

    public function test_staff_can_delete_their_own_activities(): void
    {
        $company = Company::factory()->create();
        $group = $this->setupGroupAuthority($company, 'edit');

        // Create staff user with group access
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        UserCompanyAccess::create([
            'user_id' => $staff->id,
            'company_id' => $company->id,
            'group_id' => $group->id,
        ]);
        $staff->update(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $customer = CompanyIndividual::factory()->for($company)->create();

        $create = $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'follow_up',
                'subject' => 'Follow-up call',
            ],
            $this->headers($token),
        );

        $activityId = $create->json('id');

        $delete = $this->deleteJson(
            "/api/crm/activities/{$activityId}",
            [],
            $this->headers($token),
        );

        $delete->assertNoContent();

        $list = $this->getJson('/api/crm/activities', $this->headers($token));
        $list->assertOk()->assertJsonCount(0);
    }

    public function test_activity_types_are_validated(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $create = $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'invalid_type',
                'subject' => 'Test',
            ],
            $this->headers($token),
        );

        $create->assertStatus(422);
    }

    public function test_activity_status_defaults_to_completed(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $create = $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer->id,
                'activity_type' => 'call',
                'subject' => 'Test call',
            ],
            $this->headers($token),
        );

        $create->assertOk()->assertJson(['status' => 'completed']);
    }

    public function test_can_filter_by_customer_and_activity_type(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer1 = CompanyIndividual::factory()->for($company)->create(['name' => 'Customer A']);
        $customer2 = CompanyIndividual::factory()->for($company)->create(['name' => 'Customer B']);

        // Create activities for different customers
        $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer1->id,
                'activity_type' => 'call',
                'subject' => 'Call with A',
            ],
            $this->headers($token),
        );

        $this->postJson(
            '/api/crm/activities',
            [
                'customer_id' => $customer2->id,
                'activity_type' => 'email',
                'subject' => 'Email with B',
            ],
            $this->headers($token),
        );

        // Filter by customer_id
        $filtered = $this->getJson(
            "/api/crm/activities?customer_id={$customer1->id}",
            $this->headers($token),
        );
        $filtered->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Call with A']);

        // Filter by activity_type
        $typeFiltered = $this->getJson(
            '/api/crm/activities?activity_type=email',
            $this->headers($token),
        );
        $typeFiltered->assertOk()->assertJsonCount(1)->assertJsonFragment(['subject' => 'Email with B']);
    }
}
