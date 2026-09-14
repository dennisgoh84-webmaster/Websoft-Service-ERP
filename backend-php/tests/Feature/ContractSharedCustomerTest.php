<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\ContractSharedCustomer;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW FEATURE (not a Python->PHP conversion -- see
 * docs/backlog.md / docs/planned-work.md): "Service Contract - To
 * have selection of Sharing of Hours with multiple company even not
 * in the related company file". Also covers Feature #4's contract
 * filters (remaining_hours_lt, expiry_from/expiry_to) and Feature #6's
 * quotation_reference stand-in, gated on the same "service_contracts"
 * module ContractController already uses.
 */
class ContractSharedCustomerTest extends TestCase
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

    // ---- Shared-hours customer list -------------------------------------

    public function test_owner_can_add_and_remove_a_shared_hours_customer(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $primaryCustomer = CompanyIndividual::factory()->for($company)->create();
        $sharedCustomer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $primaryCustomer->id]);

        $add = $this->postJson("/api/contracts/{$contract->id}/shared-customers", [
            'customer_id' => $sharedCustomer->id,
        ], $this->headers($token));

        $add->assertOk();
        $this->assertCount(1, $add->json('shared_customers'));
        $this->assertSame($sharedCustomer->id, $add->json('shared_customers.0.customer_id'));
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'contract', 'entity_id' => $contract->id, 'action' => 'shared_customer_added',
        ]);

        $sharedRowId = $add->json('shared_customers.0.id');
        $remove = $this->deleteJson("/api/contracts/{$contract->id}/shared-customers/{$sharedRowId}", [], $this->headers($token));
        $remove->assertOk()->assertJsonCount(0, 'shared_customers');
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);

        $this->getJson("/api/contracts/{$contract->id}", $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_cannot_add_the_contracts_own_customer_as_a_shared_customer(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);

        $this->postJson("/api/contracts/{$contract->id}/shared-customers", [
            'customer_id' => $customer->id,
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_cannot_add_the_same_shared_customer_twice(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $shared = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);
        ContractSharedCustomer::create(['contract_id' => $contract->id, 'customer_id' => $shared->id]);

        $this->postJson("/api/contracts/{$contract->id}/shared-customers", [
            'customer_id' => $shared->id,
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_view_only_group_cannot_add_a_shared_customer(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_contracts'], ['name' => 'Service Contracts', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_contracts', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_contracts', 'access_level' => GroupModuleAuthority::VIEW]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $customer = CompanyIndividual::factory()->for($company)->create();
        $shared = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);

        $this->postJson("/api/contracts/{$contract->id}/shared-customers", [
            'customer_id' => $shared->id,
        ], $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_module_control_disabled_blocks_shared_customer_endpoint(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_contracts'], ['name' => 'Service Contracts', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_contracts', 'enabled' => false]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_contracts', 'access_level' => GroupModuleAuthority::FULL]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $customer = CompanyIndividual::factory()->for($company)->create();
        $shared = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id]);

        $this->postJson("/api/contracts/{$contract->id}/shared-customers", [
            'customer_id' => $shared->id,
        ], $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_shared_customer_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $customer = CompanyIndividual::factory()->for($companyA)->create();
        $contract = Contract::factory()->for($companyA)->create(['customer_id' => $customer->id]);
        $foreignCustomer = CompanyIndividual::factory()->for($companyB)->create();

        $this->postJson("/api/contracts/{$contract->id}/shared-customers", [
            'customer_id' => $foreignCustomer->id,
        ], $this->headers($token))->assertStatus(404);
    }

    // ---- Filters: remaining_hours_lt, expiry_from/expiry_to -------------

    public function test_remaining_hours_lt_filters_contracts_below_the_threshold(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'contracted_minutes' => 600, 'consumed_minutes' => 580, // 20 min = 0.33hr remaining
        ]);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'contracted_minutes' => 600, 'consumed_minutes' => 0, // 10hr remaining
        ]);

        $response = $this->getJson('/api/contracts?remaining_hours_lt=1', $this->headers($token));

        $response->assertOk()->assertJsonCount(1);
        $this->assertEqualsWithDelta(0.333, $response->json('0.remaining_hours'), 0.01);
    }

    public function test_expiry_date_range_filters_by_the_contracts_own_end_date(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'end_date' => '2026-01-15',
        ]);
        Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'end_date' => '2026-06-15',
        ]);

        $response = $this->getJson('/api/contracts?expiry_from=2026-01-01&expiry_to=2026-02-01', $this->headers($token));

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame('2026-01-15', $response->json('0.end_date'));
    }

    // ---- Quotation reference (KNOWN GAP / pragmatic stand-in) -----------

    public function test_quotation_reference_can_only_be_set_once_renewed_or_expired(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $draftContract = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'status' => Contract::STATUS_DRAFT,
        ]);

        $this->postJson("/api/contracts/{$draftContract->id}/quotation-reference", [
            'quotation_reference' => 'QUO-2026-0099',
        ], $this->headers($token))->assertStatus(422);

        $draftContract->update(['status' => Contract::STATUS_EXPIRED]);
        $response = $this->postJson("/api/contracts/{$draftContract->id}/quotation-reference", [
            'quotation_reference' => 'QUO-2026-0099',
        ], $this->headers($token));

        $response->assertOk()->assertJson(['quotation_reference' => 'QUO-2026-0099']);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'contract', 'entity_id' => $draftContract->id, 'action' => 'quotation_reference_set',
        ]);
    }

    public function test_renew_accepts_an_optional_quotation_reference_for_the_prior_contract(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $prior = Contract::factory()->for($company)->create([
            'customer_id' => CompanyIndividual::factory()->for($company)->create()->id,
            'status' => Contract::STATUS_ACTIVE,
            'end_date' => now()->toDateString(),
        ]);

        $response = $this->postJson("/api/contracts/{$prior->id}/renew", [
            'contracted_hours' => 10, 'contract_value_sgd' => 3000,
            'quotation_reference' => 'QUO-2026-0100',
        ], $this->headers($token));

        $response->assertOk();
        $this->assertSame('QUO-2026-0100', $prior->fresh()->quotation_reference);
    }
}
