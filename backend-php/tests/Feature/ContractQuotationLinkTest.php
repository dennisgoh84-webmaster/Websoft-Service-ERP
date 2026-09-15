<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\ModuleCatalog;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SALES-006 (2026-09-15): Contract <-> Sales Quotation as a real link,
 * and the renewal quotation raised from an expiring contract whose
 * acceptance renews it.
 */
class ContractQuotationLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = CompanyIndividual::factory()->for($this->company)->create();
        foreach (['sales', 'service_contracts'] as $key) {
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

    /** An active Service Support contract ending in $daysToEnd days (negative = already past). */
    private function contract(int $daysToEnd, array $overrides = []): Contract
    {
        $end = Carbon::today()->addDays($daysToEnd);

        return Contract::factory()->for($this->company)->create($overrides + [
            'customer_id' => $this->customer->id,
            'status' => Contract::STATUS_ACTIVE,
            'contracted_minutes' => 20 * 60,
            'contract_value_sgd' => 3000,
            'start_date' => $end->copy()->subYear()->toDateString(),
            'end_date' => $end->toDateString(),
        ]);
    }

    private function walkToSent(string $quotationId): void
    {
        foreach (['submit', 'approve', 'send'] as $step) {
            $this->postJson("/api/quotations/{$quotationId}/{$step}", [], $this->h())->assertOk();
        }
    }

    // ── The link itself ─────────────────────────────────────────────

    public function test_accepting_a_quotation_links_the_contracts_it_creates_back_to_it(): void
    {
        $id = $this->postJson('/api/quotations', [
            'customer_id' => $this->customer->id, 'quotation_date' => '2026-09-15',
            'lines' => [
                ['description' => 'Support', 'unit_of_measure' => 'Hours', 'quantity' => 10, 'unit_price_sgd' => 150],
                ['description' => 'Licence', 'quantity' => 1, 'unit_price_sgd' => 800],
            ],
        ], $this->h())->json('id');
        $this->walkToSent($id);
        $accept = $this->postJson("/api/quotations/{$id}/accept", [], $this->h())->assertOk();

        foreach (['converted_contract_id', 'converted_annual_contract_id'] as $field) {
            $contract = $this->getJson('/api/contracts/'.$accept->json("quotation.{$field}"), $this->h())->assertOk();
            $this->assertSame($id, $contract->json('quotation_id'));
            $this->assertSame($accept->json('quotation.quotation_number'), $contract->json('quotation_number'));
        }
    }

    public function test_a_quotation_can_be_linked_by_hand_only_from_the_same_customer(): void
    {
        $contract = $this->contract(200);
        $mine = Quotation::factory()->for($this->company)->create(['customer_id' => $this->customer->id]);
        $other = CompanyIndividual::factory()->for($this->company)->create();
        $theirs = Quotation::factory()->for($this->company)->create(['customer_id' => $other->id]);
        $foreign = Quotation::factory()->create();

        $this->postJson("/api/contracts/{$contract->id}/quotation", ['quotation_id' => $theirs->id], $this->h())
            ->assertStatus(422);
        $this->postJson("/api/contracts/{$contract->id}/quotation", ['quotation_id' => $foreign->id], $this->h())
            ->assertStatus(404);
        $this->postJson("/api/contracts/{$contract->id}/quotation", ['quotation_id' => $mine->id], $this->h())
            ->assertOk()->assertJsonPath('quotation_id', $mine->id)->assertJsonPath('quotation_number', $mine->quotation_number);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'contract', 'entity_id' => $contract->id, 'action' => 'quotation_linked',
        ]);
    }

    public function test_renew_with_a_quotation_links_it_to_the_new_contract(): void
    {
        $prior = $this->contract(-3, ['status' => Contract::STATUS_EXPIRED]);
        $quotation = Quotation::factory()->for($this->company)->create(['customer_id' => $this->customer->id]);

        $new = $this->postJson("/api/contracts/{$prior->id}/renew", [
            'contracted_hours' => 10, 'contract_value_sgd' => 1500, 'quotation_id' => $quotation->id,
        ], $this->h())->assertOk();

        $this->assertSame($quotation->id, $new->json('quotation_id'));
        $this->assertSame($prior->id, $new->json('renewed_from_contract_id'));
        $this->assertNull($prior->fresh()->quotation_id);
    }

    // ── Renewal quotation from an expiring contract ─────────────────

    public function test_a_renewal_quotation_needs_the_contract_to_be_near_expiry_and_not_ad_hoc(): void
    {
        $farOff = $this->contract(120);
        $this->getJson("/api/contracts/{$farOff->id}", $this->h())->assertJsonPath('renewal_quotation_eligible', false);
        $this->postJson("/api/contracts/{$farOff->id}/renewal-quotation", [], $this->h())->assertStatus(422)
            ->assertJsonPath('detail', "{$farOff->contract_number} is not within 30 days of expiry yet (SRV-014), so a renewal quotation cannot be raised for it.");

        $adHoc = $this->contract(5, ['contract_kind' => Contract::KIND_AD_HOC, 'contracted_minutes' => 0, 'contract_value_sgd' => 0, 'hourly_rate_sgd' => 150]);
        $this->postJson("/api/contracts/{$adHoc->id}/renewal-quotation", [], $this->h())->assertStatus(422);

        $renewed = $this->contract(-3, ['status' => Contract::STATUS_RENEWED]);
        $this->postJson("/api/contracts/{$renewed->id}/renewal-quotation", [], $this->h())->assertStatus(422);
    }

    public function test_a_service_support_renewal_quotation_carries_the_hours_at_the_blended_rate(): void
    {
        $contract = $this->contract(20); // inside the 30-day window
        $this->getJson("/api/contracts/{$contract->id}", $this->h())->assertJsonPath('renewal_quotation_eligible', true);

        $r = $this->postJson("/api/contracts/{$contract->id}/renewal-quotation", [], $this->h())->assertOk();
        $q = $this->getJson('/api/quotations/'.$r->json('quotation_id'), $this->h())->assertOk();

        $this->assertSame('draft', $q->json('status'));
        $this->assertSame($contract->id, $q->json('renews_contract_id'));
        $this->assertSame($contract->contract_number, $q->json('renews_contract_number'));
        $this->assertSame($this->customer->id, $q->json('customer_id'));
        $this->assertCount(1, $q->json('lines'));
        $this->assertSame('Hours', $q->json('lines.0.unit_of_measure'));
        $this->assertEquals(20, $q->json('lines.0.quantity'));
        $this->assertEquals(150, $q->json('lines.0.unit_price_sgd')); // 3000 / 20
        $this->assertEquals(3000, $q->json('amount_sgd'));

        // The contract now shows it, and the button is gone until it is settled.
        $this->assertSame($r->json('quotation_id'), $r->json('contract.renewal_quotation_id'));
        $this->assertSame('draft', $r->json('contract.renewal_quotation_status'));
        $this->assertFalse($r->json('contract.renewal_quotation_eligible'));
        $this->postJson("/api/contracts/{$contract->id}/renewal-quotation", [], $this->h())->assertStatus(422);

        // Rejecting it frees the contract to raise another.
        $this->postJson('/api/quotations/'.$r->json('quotation_id').'/reject', [], $this->h())->assertOk();
        $this->getJson("/api/contracts/{$contract->id}", $this->h())
            ->assertJsonPath('renewal_quotation_id', null)->assertJsonPath('renewal_quotation_eligible', true);
    }

    public function test_an_annual_renewal_quotation_carries_the_annual_value_as_one_line(): void
    {
        $contract = $this->contract(-1, ['status' => Contract::STATUS_EXPIRED, 'contract_kind' => Contract::KIND_ANNUAL, 'contracted_minutes' => 0, 'contract_value_sgd' => 4200]);

        $r = $this->postJson("/api/contracts/{$contract->id}/renewal-quotation", [], $this->h())->assertOk();
        $q = $this->getJson('/api/quotations/'.$r->json('quotation_id'), $this->h());

        $this->assertNull($q->json('lines.0.unit_of_measure'));
        $this->assertEquals(1, $q->json('lines.0.quantity'));
        $this->assertEquals(4200, $q->json('lines.0.unit_price_sgd'));
    }

    public function test_accepting_a_renewal_quotation_renews_the_contract(): void
    {
        $prior = $this->contract(10);
        $quotationId = $this->postJson("/api/contracts/{$prior->id}/renewal-quotation", [], $this->h())->json('quotation_id');
        $this->walkToSent($quotationId);

        $accept = $this->postJson("/api/quotations/{$quotationId}/accept", [], $this->h())->assertOk();
        $this->assertStringContainsString("{$prior->contract_number} renewed as", $accept->json('message'));

        $prior->refresh();
        $this->assertSame(Contract::STATUS_RENEWED, $prior->status);

        $newId = $accept->json('quotation.converted_contract_id');
        $this->assertNotNull($newId);
        $new = $this->getJson("/api/contracts/{$newId}", $this->h())->assertOk();
        $this->assertSame($prior->id, $new->json('renewed_from_contract_id'));
        $this->assertSame($quotationId, $new->json('quotation_id'));
        $this->assertEquals(20, $new->json('contracted_hours'));
        $this->assertEquals(3000, $new->json('contract_value_sgd'));
        // SRV-016: seamless -- the new term starts where the old one ended.
        $this->assertSame($prior->end_date->toDateString(), $new->json('start_date'));
        // Exactly one contract came out of it: a renewal, not a fresh one beside the old.
        $this->assertSame(2, Contract::where('customer_id', $this->customer->id)->count());
    }

    public function test_a_renewal_quotation_sent_back_to_revise_is_revised_and_the_revision_renews_the_contract(): void
    {
        $prior = $this->contract(10);
        $originalId = $this->postJson("/api/contracts/{$prior->id}/renewal-quotation", [], $this->h())->json('quotation_id');
        $this->walkToSent($originalId);
        $this->postJson("/api/quotations/{$originalId}/to-revise", ['reason' => 'More hours'], $this->h())->assertOk();

        // While it awaits its revision the contract still shows it, and will not raise a second one.
        $this->getJson("/api/contracts/{$prior->id}", $this->h())
            ->assertJsonPath('renewal_quotation_id', $originalId)
            ->assertJsonPath('renewal_quotation_status', 'to_revise')
            ->assertJsonPath('renewal_quotation_eligible', false);

        $revisionId = $this->postJson("/api/quotations/{$originalId}/revise", [], $this->h())->assertOk()->json('id');
        $this->getJson("/api/quotations/{$revisionId}", $this->h())->assertJsonPath('renews_contract_id', $prior->id);
        $this->getJson("/api/contracts/{$prior->id}", $this->h())->assertJsonPath('renewal_quotation_id', $revisionId);

        $this->walkToSent($revisionId);
        $accept = $this->postJson("/api/quotations/{$revisionId}/accept", [], $this->h())->assertOk();
        $this->assertStringContainsString('renewed as', $accept->json('message'));
        $this->assertSame(Contract::STATUS_RENEWED, $prior->fresh()->status);
    }

    public function test_accepting_a_renewal_quotation_past_the_backdating_window_leaves_the_decision_to_a_human(): void
    {
        $prior = $this->contract(-30, ['status' => Contract::STATUS_EXPIRED]); // well past SRV-016's 2 weeks
        $quotationId = $this->postJson("/api/contracts/{$prior->id}/renewal-quotation", [], $this->h())->json('quotation_id');
        $this->walkToSent($quotationId);

        $accept = $this->postJson("/api/quotations/{$quotationId}/accept", [], $this->h())->assertOk();

        $this->assertSame('accepted', $accept->json('quotation.status'));
        $this->assertNull($accept->json('quotation.converted_contract_id'));
        $this->assertStringContainsString('was not renewed', $accept->json('message'));
        $this->assertStringContainsString('SRV-018', $accept->json('message'));
        $this->assertSame(Contract::STATUS_EXPIRED, $prior->fresh()->status);
        $this->assertSame(1, Contract::where('customer_id', $this->customer->id)->count());
    }
}
