<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\Prospect;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prospect / Leads (2026-09-26): the prospect, its activities, its
 * quotations and the invoices they lead to, and what it reports as
 * estimated / quoted / billed / paid / outstanding.
 */
class ProspectTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $customer;

    private Group $salesGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = CompanyIndividual::factory()->for($this->company)->create();
        foreach (['prospects' => 'Prospect / Leads', 'sales' => 'Sales'] as $key => $name) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $name, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $key], ['enabled' => true, 'license_type' => CompanyModule::INCLUDED]);
        }
        $this->salesGroup = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create(['group_id' => $this->salesGroup->id, 'module_key' => 'prospects', 'access_level' => GroupModuleAuthority::EDIT]);
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function login(string $role): array
    {
        $user = User::factory()->for($this->company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $this->salesGroup->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    private function newProspect(array $h, array $extra = []): array
    {
        return $this->postJson('/api/prospects', ['customer_id' => $this->customer->id, 'title' => 'Server room upgrade'] + $extra, $h)
            ->assertOk()->json();
    }

    public function test_a_prospect_is_numbered_owned_by_its_creator_and_carries_its_activities(): void
    {
        [$staff, $h] = $this->login(User::ROLE_SALES_STAFF);

        $p = $this->newProspect($h, ['estimated_value_sgd' => 18000, 'source' => 'Referral']);
        $this->assertStringStartsWith('PRS-', $p['prospect_number']);
        $this->assertSame($staff->id, $p['salesperson_user_id']);
        $this->assertSame('open', $p['status']);
        $this->assertEquals(18000, $p['estimated_value_sgd']);

        $this->postJson('/api/prospect-activities', ['prospect_id' => $p['id'], 'activity_type' => 'meeting', 'subject' => 'Site survey'], $h)
            ->assertOk()->assertJson(['prospect_id' => $p['id'], 'customer_id' => $this->customer->id, 'created_by_name' => $staff->full_name]);
        $this->getJson("/api/prospects/{$p['id']}", $h)->assertOk()->assertJsonCount(1, 'activities');
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'prospect', 'entity_id' => $p['id'], 'action' => 'created']);
    }

    public function test_an_activity_must_belong_to_a_prospect_the_user_can_see(): void
    {
        [, $h] = $this->login(User::ROLE_SALES_STAFF);
        $this->postJson('/api/prospect-activities', ['activity_type' => 'call', 'subject' => 'x'], $h)->assertStatus(422);

        [, $other] = $this->login(User::ROLE_SALES_STAFF);
        $theirs = $this->newProspect($other);
        $this->postJson('/api/prospect-activities', ['prospect_id' => $theirs['id'], 'activity_type' => 'call', 'subject' => 'x'], $h)->assertStatus(404);
    }

    public function test_sales_staff_see_their_own_prospects_and_supervisor_manager_owner_see_all(): void
    {
        [, $a] = $this->login(User::ROLE_SALES_STAFF);
        [, $b] = $this->login(User::ROLE_SALES_STAFF);
        $pa = $this->newProspect($a);
        $this->newProspect($b);
        $this->postJson('/api/prospect-activities', ['prospect_id' => $pa['id'], 'activity_type' => 'call', 'subject' => 'A call'], $a)->assertOk();

        $this->getJson('/api/prospects', $a)->assertOk()->assertJsonCount(1);
        $this->getJson("/api/prospects/{$pa['id']}", $b)->assertStatus(404);
        $this->getJson('/api/prospect-activities', $b)->assertOk()->assertJsonCount(0);

        foreach ([User::ROLE_SALES_SUPERVISOR, User::ROLE_SALES_MANAGER, User::ROLE_OWNER] as $role) {
            [, $h] = $this->login($role);
            $this->getJson('/api/prospects', $h)->assertOk()->assertJsonCount(2);
            $this->getJson('/api/prospect-activities', $h)->assertOk()->assertJsonCount(1);
        }
    }

    public function test_only_sales_management_can_hand_a_prospect_to_another_salesperson(): void
    {
        [, $staffH] = $this->login(User::ROLE_SALES_STAFF);
        [$other] = $this->login(User::ROLE_SALES_STAFF);
        $this->postJson('/api/prospects', ['customer_id' => $this->customer->id, 'title' => 'x', 'salesperson_user_id' => $other->id], $staffH)
            ->assertStatus(403);

        [, $supH] = $this->login(User::ROLE_SALES_SUPERVISOR);
        $this->postJson('/api/prospects', ['customer_id' => $this->customer->id, 'title' => 'x', 'salesperson_user_id' => $other->id], $supH)
            ->assertOk()->assertJson(['salesperson_user_id' => $other->id]);
    }

    public function test_marking_lost_needs_a_reason_and_the_company_cannot_change(): void
    {
        [, $h] = $this->login(User::ROLE_SALES_MANAGER);
        $p = $this->newProspect($h);

        $this->patchJson("/api/prospects/{$p['id']}", ['status' => 'lost'], $h)->assertStatus(422);
        $this->patchJson("/api/prospects/{$p['id']}", ['status' => 'lost', 'lost_reason' => 'Went with a competitor'], $h)
            ->assertOk()->assertJson(['status' => 'lost']);
        $other = CompanyIndividual::factory()->for($this->company)->create();
        $this->patchJson("/api/prospects/{$p['id']}", ['customer_id' => $other->id], $h)->assertStatus(422);
    }

    public function test_quotation_to_invoice_ties_back_and_the_prospect_reports_every_amount(): void
    {
        [, $h] = $this->login(User::ROLE_OWNER);
        $p = $this->newProspect($h, ['estimated_value_sgd' => 5000]);

        $q = $this->postJson('/api/quotations', [
            'customer_id' => $this->customer->id, 'prospect_id' => $p['id'], 'quotation_date' => now()->toDateString(),
            'lines' => [['description' => 'On-site support', 'unit_of_measure' => 'Hours', 'quantity' => 10, 'unit_price_sgd' => 300]],
        ], $h)->assertOk()->assertJson(['prospect_id' => $p['id'], 'prospect_number' => $p['prospect_number']])->json();
        // A second quotation on the same prospect.
        $this->postJson('/api/quotations', [
            'customer_id' => $this->customer->id, 'prospect_id' => $p['id'], 'quotation_date' => now()->toDateString(),
            'lines' => [['description' => 'Hardware', 'quantity' => 1, 'unit_price_sgd' => 700]],
        ], $h)->assertOk();

        // Drafts are not yet quoted to the customer.
        $this->getJson("/api/prospects/{$p['id']}", $h)->assertJson(['estimated_value_sgd' => 5000, 'quoted_amount_sgd' => 0])->assertJsonCount(2, 'quotations');

        foreach (['submit', 'approve', 'send'] as $step) {
            $this->postJson("/api/quotations/{$q['id']}/{$step}", [], $h)->assertOk();
        }
        $this->getJson("/api/prospects/{$p['id']}", $h)->assertJson(['quoted_amount_sgd' => 3000]);

        $contractId = $this->postJson("/api/quotations/{$q['id']}/accept", [], $h)->assertOk()->json('quotation.converted_contract_id');
        $this->postJson("/api/contracts/{$contractId}/activate", [], $h)->assertOk();

        $invoice = Invoice::where('contract_id', $contractId)->firstOrFail();
        $this->assertSame($p['id'], $invoice->prospect_id, 'the invoice raised from the accepted quotation is tied back to the prospect');

        $invoice->update(['amount_paid_sgd' => 1000, 'status' => Invoice::STATUS_PARTIALLY_PAID]);
        $this->getJson("/api/prospects/{$p['id']}", $h)->assertOk()->assertJson([
            'estimated_value_sgd' => 5000,
            'quoted_amount_sgd' => 3000,
            'billed_amount_sgd' => (float) $invoice->total_amount_sgd,
            'paid_amount_sgd' => 1000,
            'outstanding_amount_sgd' => (float) $invoice->total_amount_sgd - 1000,
        ])->assertJsonCount(1, 'invoices');
    }

    public function test_a_quotation_prospect_must_be_for_the_same_company_individual(): void
    {
        [, $h] = $this->login(User::ROLE_OWNER);
        $p = $this->newProspect($h);
        $other = CompanyIndividual::factory()->for($this->company)->create();

        $this->postJson('/api/quotations', [
            'customer_id' => $other->id, 'prospect_id' => $p['id'], 'quotation_date' => now()->toDateString(),
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price_sgd' => 1]],
        ], $h)->assertStatus(422);
    }

    public function test_a_revision_stays_on_the_prospect(): void
    {
        [, $h] = $this->login(User::ROLE_OWNER);
        $p = $this->newProspect($h);
        $q = $this->postJson('/api/quotations', [
            'customer_id' => $this->customer->id, 'prospect_id' => $p['id'], 'quotation_date' => now()->toDateString(),
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price_sgd' => 100]],
        ], $h)->json();
        foreach (['submit', 'approve', 'send'] as $step) {
            $this->postJson("/api/quotations/{$q['id']}/{$step}", [], $h)->assertOk();
        }
        $this->postJson("/api/quotations/{$q['id']}/to-revise", ['reason' => 'Customer wants fewer hours'], $h)->assertOk();
        $revision = $this->postJson("/api/quotations/{$q['id']}/revise", [], $h)->assertOk()->json();

        $this->assertSame($p['id'], $revision['prospect_id'] ?? $revision['quotation']['prospect_id'] ?? null);
    }

    public function test_linking_an_earlier_quotation_brings_its_invoices_with_it(): void
    {
        [, $h] = $this->login(User::ROLE_OWNER);
        $q = $this->postJson('/api/quotations', [
            'customer_id' => $this->customer->id, 'quotation_date' => now()->toDateString(),
            'lines' => [['description' => 'Support', 'unit_of_measure' => 'Hours', 'quantity' => 10, 'unit_price_sgd' => 200]],
        ], $h)->json();
        foreach (['submit', 'approve', 'send'] as $step) {
            $this->postJson("/api/quotations/{$q['id']}/{$step}", [], $h)->assertOk();
        }
        $contractId = $this->postJson("/api/quotations/{$q['id']}/accept", [], $h)->json('quotation.converted_contract_id');
        $this->postJson("/api/contracts/{$contractId}/activate", [], $h)->assertOk();
        $this->assertNull(Invoice::where('contract_id', $contractId)->value('prospect_id'));

        $p = $this->newProspect($h);
        $this->postJson("/api/quotations/{$q['id']}/prospect", ['prospect_id' => $p['id']], $h)->assertOk()->assertJson(['prospect_id' => $p['id']]);

        $this->assertSame($p['id'], Invoice::where('contract_id', $contractId)->value('prospect_id'));
        $this->assertGreaterThan(0, $this->getJson("/api/prospects/{$p['id']}", $h)->json('billed_amount_sgd'));
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'quotation', 'entity_id' => $q['id'], 'action' => 'prospect_linked']);
    }

    public function test_the_prospects_module_key_gates_the_screens(): void
    {
        [, $h] = $this->login(User::ROLE_SALES_STAFF);
        CompanyModule::where('company_id', $this->company->id)->where('module_key', 'prospects')->update(['enabled' => false]);

        $this->getJson('/api/prospects', $h)->assertStatus(403);
        $this->getJson('/api/prospect-activities', $h)->assertStatus(403);
    }

    public function test_staff_master_accepts_the_new_sales_roles(): void
    {
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token')];

        foreach (['sales_staff' => 'salesman1', 'sales_supervisor' => 'supervisor1'] as $role => $username) {
            $this->postJson('/api/users', [
                'username' => $username, 'email' => "{$username}@example.com", 'password' => 'demo1234',
                'full_name' => ucfirst($username), 'role' => $role,
            ], $h)->assertOk()->assertJson(['role' => $role]);
        }
        $this->postJson('/api/users', [
            'username' => 'nobody1', 'email' => 'nobody1@example.com', 'password' => 'demo1234', 'full_name' => 'X', 'role' => 'sales_engineer',
        ], $h)->assertStatus(422);
    }
}
