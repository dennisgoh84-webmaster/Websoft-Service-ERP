<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\ModuleCatalog;
use App\Models\SetupListItem;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 2026-09-15, Dennis's Maintenance batch: City / Product Category /
 * Unit of Measure / Relationship as setup lists, the Country filter
 * for State and City, and the PDPA data expiry defaulting to five
 * years from the e-signed date.
 */
class MaintenanceListsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        foreach (['core_administration', 'company_individual_management'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $key], ['enabled' => true]);
        }
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
    }

    private function h(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_the_four_new_lists_are_accepted_and_an_unknown_one_is_not(): void
    {
        foreach (['city', 'product_category', 'unit_of_measure', 'relationship'] as $type) {
            $this->postJson('/api/setup-lists', ['list_type' => $type, 'code' => 'X1', 'name' => "Item of {$type}"], $this->h())
                ->assertOk();
        }
        $this->postJson('/api/setup-lists', ['list_type' => 'colour', 'code' => 'RED', 'name' => 'Red'], $this->h())
            ->assertStatus(422);
        $this->getJson('/api/setup-lists?list_type=relationship', $this->h())->assertOk()->assertJsonCount(1);
    }

    public function test_states_and_cities_can_be_narrowed_to_one_country(): void
    {
        SetupListItem::create(['list_type' => 'country', 'code' => 'SG', 'name' => 'Singapore']);
        SetupListItem::create(['list_type' => 'country', 'code' => 'MY', 'name' => 'Malaysia']);
        SetupListItem::create(['list_type' => 'state', 'code' => 'JHR', 'name' => 'Johor', 'parent_code' => 'MY']);
        SetupListItem::create(['list_type' => 'state', 'code' => 'SEL', 'name' => 'Selangor', 'parent_code' => 'MY']);
        SetupListItem::create(['list_type' => 'city', 'code' => 'SIN', 'name' => 'Singapore', 'parent_code' => 'SG']);
        SetupListItem::create(['list_type' => 'city', 'code' => 'JB', 'name' => 'Johor Bahru', 'parent_code' => 'MY']);

        $this->getJson('/api/setup-lists?list_type=state&parent_code=MY', $this->h())->assertOk()->assertJsonCount(2);
        $this->getJson('/api/setup-lists?list_type=state&parent_code=SG', $this->h())->assertOk()->assertJsonCount(0);
        $this->getJson('/api/setup-lists?list_type=city&parent_code=SG', $this->h())->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.name', 'Singapore');
    }

    public function test_recording_pdpa_consent_defaults_the_data_expiry_to_five_years_later(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $customer = CompanyIndividual::factory()->for($this->company)->create(['data_expiry_date' => null]);

        $this->postJson("/api/company-individuals/{$customer->id}/pdpa-consent", ['given' => true], $this->h())
            ->assertOk();
        $this->assertSame('2031-09-15', $customer->fresh()->data_expiry_date->toDateString());

        // A date already chosen is kept when consent is (re)recorded.
        $chosen = CompanyIndividual::factory()->for($this->company)->create(['data_expiry_date' => '2028-01-31']);
        $this->postJson("/api/company-individuals/{$chosen->id}/pdpa-consent", ['given' => true], $this->h())->assertOk();
        $this->assertSame('2028-01-31', $chosen->fresh()->data_expiry_date->toDateString());

        // And it stays editable from the PDPA card.
        $this->patchJson("/api/company-individuals/{$customer->id}", ['data_expiry_date' => '2030-06-30'], $this->h())->assertOk();
        $this->assertSame('2030-06-30', $customer->fresh()->data_expiry_date->toDateString());
        Carbon::setTestNow();
    }
}
