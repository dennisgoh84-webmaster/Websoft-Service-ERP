<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW FEATURE (not a Python->PHP conversion -- backend/ has no
 * equivalent; built directly in backend-php per Dennis's request, see
 * docs/backlog.md / docs/planned-work.md): "Service Contract
 * Operation Report - Contract Expiry Listing, Contract due for
 * renewal Listing".
 */
class ContractReportTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_expiry_listing_returns_contracts_in_range_and_already_expired(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $inRange = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);
        $alreadyExpired = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'status' => Contract::STATUS_EXPIRED,
            'end_date' => now()->subYears(1)->toDateString(),
        ]);
        $outOfRange = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'status' => Contract::STATUS_ACTIVE,
            'end_date' => now()->addYears(2)->toDateString(),
        ]);

        $response = $this->getJson(
            '/api/reports/operations/contracts/expiry-listing?expiry_from='.now()->toDateString().'&expiry_to='.now()->addDays(30)->toDateString(),
            $this->headers($token),
        );

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($inRange->id, $ids);
        $this->assertContains($alreadyExpired->id, $ids);
        $this->assertNotContains($outOfRange->id, $ids);
    }

    public function test_renewal_due_listing_matches_srv014_window(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: now()->subMonths(11)->toDateString(), actorUserId: $actor->id,
        );
        ContractService::activateContract($contract, $actor->id);
        $contract->end_date = now()->addDays(15)->toDateString();
        $contract->save();

        $response = $this->getJson('/api/reports/operations/contracts/renewal-due-listing', $this->headers($token));

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($contract->id, $response->json('0.id'));
    }

    public function test_csv_export_is_downloadable(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'status' => Contract::STATUS_EXPIRED,
        ]);

        $response = $this->get('/api/reports/operations/contracts/expiry-listing/export.csv', $this->headers($token));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_view_only_group_can_read_but_module_control_disabled_is_blocked(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'operations_reports'], ['name' => 'Operations Reports', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'operations_reports', 'enabled' => false]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'operations_reports', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/operations/contracts/expiry-listing', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/reports/operations/contracts/expiry-listing', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_report_from_another_company_never_leaks(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        Contract::factory()->for($companyB)->create([
            'customer_id' => CompanyIndividual::factory()->for($companyB)->create()->id,
            'status' => Contract::STATUS_EXPIRED,
        ]);

        $response = $this->getJson('/api/reports/operations/contracts/expiry-listing', $this->headers($token));

        $response->assertOk()->assertJsonCount(0);
    }

    public function test_expiry_listing_can_cover_several_of_the_users_companies(): void
    {
        $companyA = Company::factory()->create(['code' => 'C001', 'name' => 'Alpha']);
        $companyB = Company::factory()->create(['code' => 'C002', 'name' => 'Beta']);
        $token = $this->ownerToken($companyA);
        foreach ([$companyA, $companyB] as $company) {
            Contract::factory()->for($company)->create([
                'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
                'end_date' => now()->addDays(10)->toDateString(),
            ]);
        }
        $range = 'expiry_from='.now()->toDateString().'&expiry_to='.now()->addDays(30)->toDateString();

        $this->getJson("/api/reports/operations/contracts/expiry-listing?{$range}", $this->headers($token))->assertOk()->assertJsonCount(1);
        $both = $this->getJson("/api/reports/operations/contracts/expiry-listing?{$range}&company_ids={$companyA->id},{$companyB->id}", $this->headers($token))
            ->assertOk()->assertJsonCount(2);
        $this->assertEqualsCanonicalizing(['C001 Alpha', 'C002 Beta'], array_column($both->json(), 'company_name'));

        $csv = $this->get("/api/reports/operations/contracts/expiry-listing/export.csv?{$range}&company_ids={$companyA->id},{$companyB->id}", $this->headers($token))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Internal Company', strtok($csv, "\n"));
    }
}
