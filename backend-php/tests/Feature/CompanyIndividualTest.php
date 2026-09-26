<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mirrors backend/app/routers/company_individuals.py's core CRUD +
 * RBAC + multi-company scoping + audit trail, the pattern every other
 * converted (and future) module follows -- see
 * docs/php-conversion-plan.md.
 */
class CompanyIndividualTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): string
    {
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_owner_can_create_and_list_customers(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/company-individuals', [
            'name' => 'Acme Manufacturing Pte Ltd',
            'customer_type' => 'company',
        ], $this->authHeaders($token));
        $create->assertOk()->assertJson(['name' => 'ACME MANUFACTURING PTE LTD']);

        $list = $this->getJson('/api/company-individuals', $this->authHeaders($token));
        $list->assertOk()->assertJsonCount(1);
    }

    public function test_is_customer_defaults_true_and_is_supplier_can_be_set(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/company-individuals', [
            'name' => 'Best Office Supplies Pte Ltd', 'customer_type' => 'company', 'is_supplier' => true,
        ], $this->authHeaders($token));

        $create->assertOk()->assertJson(['is_customer' => true, 'is_supplier' => true]);
    }

    public function test_po_and_credit_note_approval_limits_are_kept_on_the_file_and_audited(): void
    {
        $company = Company::factory()->create();
        $h = $this->authHeaders($this->ownerToken($company));

        $id = $this->postJson('/api/company-individuals', [
            'name' => 'Best Office Supplies Pte Ltd', 'is_supplier' => true, 'po_approval_limit_sgd' => 5000,
        ], $h)->assertOk()->assertJson(['po_approval_limit_sgd' => 5000, 'credit_note_approval_limit_sgd' => null])->json('id');

        $this->patchJson("/api/company-individuals/{$id}", ['credit_note_approval_limit_sgd' => 800, 'po_approval_limit_sgd' => null], $h)
            ->assertOk()->assertJson(['po_approval_limit_sgd' => null, 'credit_note_approval_limit_sgd' => 800]);
        $this->patchJson("/api/company-individuals/{$id}", ['po_approval_limit_sgd' => -1], $h)->assertStatus(422);

        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'customer', 'entity_id' => $id, 'action' => 'updated']);
    }

    public function test_the_customer_credit_limit_is_its_own_setting_shown_against_what_is_owed(): void
    {
        $company = Company::factory()->create();
        $h = $this->authHeaders($this->ownerToken($company));
        $id = $this->postJson('/api/company-individuals', ['name' => 'Credit Co', 'credit_limit_sgd' => 1000, 'credit_note_approval_limit_sgd' => 200], $h)
            ->assertOk()->assertJson(['credit_limit_sgd' => 1000, 'credit_note_approval_limit_sgd' => 200])->json('id');

        Invoice::create([
            'company_id' => $company->id, 'customer_id' => $id, 'invoice_number' => 'INV-CL-1', 'invoice_type' => Invoice::TYPE_SALES,
            'description' => 'x', 'amount_sgd' => 1100, 'tax_code' => 'SR', 'gst_rate' => 9, 'gst_amount_sgd' => 99, 'total_amount_sgd' => 1199,
        ]);

        $this->getJson("/api/company-individuals/{$id}", $h)->assertOk()
            ->assertJson(['outstanding_sgd' => 1199, 'over_credit_limit' => true]);
        $this->patchJson("/api/company-individuals/{$id}", ['credit_limit_sgd' => 5000], $h)->assertOk();
        $this->getJson("/api/company-individuals/{$id}", $h)->assertJson(['over_credit_limit' => false]);
    }

    public function test_the_id_and_name_are_always_full_capitals(): void
    {
        $company = Company::factory()->create();
        $h = $this->authHeaders($this->ownerToken($company));

        // Typed, or pasted with stray spaces.
        $id = $this->postJson('/api/company-individuals', ['name' => "  acme   Logistics pte ltd\t", 'legacy_customer_code' => ' c-001 '], $h)
            ->assertOk()->assertJson(['name' => 'ACME LOGISTICS PTE LTD', 'legacy_customer_code' => 'C-001'])->json('id');
        $this->patchJson("/api/company-individuals/{$id}", ['name' => 'Acme Logistics (S) Pte. Ltd.'], $h)
            ->assertOk()->assertJson(['name' => 'ACME LOGISTICS (S) PTE. LTD.']);
        // Non-Latin names are left as they are; Latin letters with accents are capitalised.
        $this->patchJson("/api/company-individuals/{$id}", ['name' => '新加坡 café'], $h)->assertOk()->assertJson(['name' => '新加坡 CAFÉ']);
    }

    public function test_existing_names_are_converted_to_capitals_and_each_change_is_logged(): void
    {
        $company = Company::factory()->create();
        $ci = CompanyIndividual::factory()->for($company)->create();
        DB::table('company_individuals')->where('id', $ci->id)->update(['name' => 'Old  mixed Case', 'legacy_customer_code' => 'ab1']);
        $already = CompanyIndividual::factory()->for($company)->create(['name' => 'ALREADY FINE']);

        (require database_path('migrations/2026_09_30_002900_company_individual_names_in_capitals.php'))->up();

        $this->assertSame('OLD MIXED CASE', $ci->fresh()->name);
        $this->assertSame('AB1', $ci->fresh()->legacy_customer_code);
        $this->assertDatabaseHas('audit_log_entries', ['entity_id' => $ci->id, 'action' => 'standardised_to_capitals']);
        $this->assertDatabaseMissing('audit_log_entries', ['entity_id' => $already->id, 'action' => 'standardised_to_capitals']);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, // not owner, no Group assigned
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $this->getJson('/api/company-individuals', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_view_only_group_cannot_create(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'company_individual_management'], ['name' => 'Customer Management', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id,
            'module_key' => 'company_individual_management',
            'access_level' => GroupModuleAuthority::VIEW,
        ]);
        CompanyModule::create([
            'company_id' => $company->id,
            'module_key' => 'company_individual_management',
            'enabled' => true,
        ]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);

        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $this->getJson('/api/company-individuals', $this->authHeaders($token))->assertOk();
        $this->postJson('/api/company-individuals', ['name' => 'X'], $this->authHeaders($token))->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'company_individual_management'], ['name' => 'Customer Management', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id,
            'module_key' => 'company_individual_management',
            'access_level' => GroupModuleAuthority::FULL,
        ]);
        // No CompanyModule row at all -- Module Control fails closed.
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);

        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $this->getJson('/api/company-individuals', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_customer_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $otherCustomer = CompanyIndividual::factory()->for($companyB)->create();

        $token = $this->ownerToken($companyA);

        $this->getJson("/api/company-individuals/{$otherCustomer->id}", $this->authHeaders($token))
            ->assertStatus(404);
    }

    public function test_archive_writes_an_audit_trail_entry(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $this->postJson("/api/company-individuals/{$customer->id}/archive", ['reason' => 'Customer closed down'], $this->authHeaders($token))
            ->assertOk()->assertJson(['is_archived' => true]);

        $log = $this->getJson("/api/company-individuals/{$customer->id}/audit-log", $this->authHeaders($token));
        $log->assertOk();
        $this->assertContains('archived', collect($log->json())->pluck('action')->all());
    }

    public function test_archiving_before_expiry_is_for_the_owner_with_a_reason_and_unarchiving_needs_one(): void
    {
        $company = Company::factory()->create();
        $ownerH = $this->authHeaders($this->ownerToken($company));
        ModuleCatalog::firstOrCreate(['key' => 'company_individual_management'], ['name' => 'Customer Management', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'company_individual_management'], ['enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'company_individual_management', 'access_level' => GroupModuleAuthority::FULL]);
        $clerk = User::factory()->for($company)->create(['role' => User::ROLE_FINANCE, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $clerk->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $clerkH = $this->authHeaders($this->post('/api/auth/login', ['username' => $clerk->email, 'password' => 'demo1234'])->json('access_token'));

        $current = CompanyIndividual::factory()->for($company)->create(['data_expiry_date' => now()->addYear()->toDateString()]);
        $expired = CompanyIndividual::factory()->for($company)->create(['data_expiry_date' => now()->subDay()->toDateString()]);

        // Before expiry: not for FULL access alone, and the owner must give a reason.
        $this->postJson("/api/company-individuals/{$current->id}/archive", ['reason' => 'x'], $clerkH)->assertStatus(403);
        $this->postJson("/api/company-individuals/{$current->id}/archive", [], $ownerH)->assertStatus(422);
        $this->postJson("/api/company-individuals/{$current->id}/archive", ['reason' => 'Asked to be forgotten'], $ownerH)->assertOk();
        $this->assertDatabaseHas('audit_log_entries', ['entity_id' => $current->id, 'action' => 'archived', 'reason' => 'Asked to be forgotten']);

        // Past expiry: anyone with FULL access, no reason needed.
        $this->postJson("/api/company-individuals/{$expired->id}/archive", [], $clerkH)->assertOk()->assertJson(['is_archived' => true]);

        // Bringing one back needs a reason.
        $this->postJson("/api/company-individuals/{$expired->id}/unarchive", [], $clerkH)->assertStatus(422);
        $this->postJson("/api/company-individuals/{$expired->id}/unarchive", ['reason' => 'Archived by mistake'], $clerkH)->assertOk()->assertJson(['is_archived' => false]);
    }

    public function test_pdpa_consent_is_server_timestamped(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $response = $this->postJson(
            "/api/company-individuals/{$customer->id}/pdpa-consent",
            ['given' => true],
            $this->authHeaders($token),
        );

        $response->assertOk()->assertJson(['pdpa_consent_given' => true]);
        $this->assertNotNull($response->json('pdpa_consent_at'));
    }

    public function test_can_add_a_contact_under_a_customer(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $response = $this->postJson(
            "/api/company-individuals/{$customer->id}/contacts",
            ['name' => 'Jane Tan', 'email' => 'jane@example.com'],
            $this->authHeaders($token),
        );

        $response->assertOk()->assertJson(['name' => 'Jane Tan', 'customer_id' => $customer->id]);
    }
}
