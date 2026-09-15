<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\Quotation;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Quotation status model settled 2026-09-15 (BILL-006): draft ->
 * pending_approval -> approved -> sent -> accepted / rejected, with
 * send-back from pending_approval to draft. And the two Sales
 * Dashboard tiles that model finally lets us count.
 */
class QuotationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = CompanyIndividual::factory()->for($this->company)->create();
        foreach (['sales', 'reporting'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $key], ['enabled' => true]);
        }
    }

    /** A staff member in a group with EDIT on sales -- can raise and submit, cannot approve. */
    private function tokenFor(string $role): string
    {
        $user = User::factory()->for($this->company)->create([
            'role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        if ($role !== User::ROLE_OWNER) {
            $group = Group::firstOrCreate(['company_id' => $this->company->id, 'name' => 'Sales']);
            foreach (['sales', 'reporting'] as $key) {
                GroupModuleAuthority::updateOrCreate(['group_id' => $group->id, 'module_key' => $key], ['access_level' => 'full']);
            }
            UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        }

        return $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');
    }

    private function h(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function draft(): Quotation
    {
        return Quotation::factory()->for($this->company)->create(['customer_id' => $this->customer->id]);
    }

    public function test_a_support_engineer_can_submit_but_not_approve(): void
    {
        $engineer = $this->tokenFor(User::ROLE_SUPPORT_ENGINEER);
        $q = $this->draft();

        $this->postJson("/api/quotations/{$q->id}/submit", [], $this->h($engineer))
            ->assertOk()->assertJsonPath('status', 'pending_approval')
            ->assertJsonMissingPath('approved_at.x');
        $this->assertNotNull($q->fresh()->submitted_at);

        $this->postJson("/api/quotations/{$q->id}/approve", [], $this->h($engineer))->assertStatus(409)
            ->assertJsonPath('detail', 'Only the Sales Manager or the owner can approve a quotation (BILL-006).');
        $this->assertSame('pending_approval', $q->fresh()->status);
    }

    public function test_the_sales_manager_approves_and_the_send_follows(): void
    {
        $engineer = $this->tokenFor(User::ROLE_SUPPORT_ENGINEER);
        $manager = $this->tokenFor(User::ROLE_SALES_MANAGER);
        $q = $this->draft();

        // Cannot send before approval.
        $this->postJson("/api/quotations/{$q->id}/send", [], $this->h($engineer))->assertStatus(409);

        $this->postJson("/api/quotations/{$q->id}/submit", [], $this->h($engineer))->assertOk();
        $this->postJson("/api/quotations/{$q->id}/approve", [], $this->h($manager))
            ->assertOk()->assertJsonPath('status', 'approved');
        $approved = $q->fresh();
        $this->assertNotNull($approved->approved_at);
        $this->assertNotNull($approved->approved_by_user_id);

        $this->postJson("/api/quotations/{$q->id}/send", [], $this->h($engineer))
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->assertNotNull($q->fresh()->sent_at);

        // Approving twice, or approving something already sent, is refused.
        $this->postJson("/api/quotations/{$q->id}/approve", [], $this->h($manager))->assertStatus(409);
    }

    public function test_send_back_returns_it_to_draft_with_the_reason_and_a_resubmit_clears_it(): void
    {
        $manager = $this->tokenFor(User::ROLE_SALES_MANAGER);
        $q = $this->draft();
        $this->postJson("/api/quotations/{$q->id}/submit", [], $this->h($manager))->assertOk();

        $this->postJson("/api/quotations/{$q->id}/send-back", [], $this->h($manager))->assertStatus(422);
        $this->postJson("/api/quotations/{$q->id}/send-back", ['reason' => 'Discount too deep'], $this->h($manager))
            ->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('returned_reason', 'Discount too deep');
        $this->assertNull($q->fresh()->submitted_at);

        $this->postJson("/api/quotations/{$q->id}/submit", [], $this->h($manager))
            ->assertOk()->assertJsonPath('returned_reason', null);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'quotation', 'entity_id' => $q->id, 'action' => 'sent_back', 'reason' => 'Discount too deep',
        ]);
    }

    public function test_accept_needs_a_sent_quotation_but_reject_works_from_any_earlier_state(): void
    {
        $owner = $this->tokenFor(User::ROLE_OWNER);

        $draft = $this->draft();
        $this->postJson("/api/quotations/{$draft->id}/accept", [], $this->h($owner))->assertStatus(409);

        $approved = $this->draft();
        $this->postJson("/api/quotations/{$approved->id}/submit", [], $this->h($owner))->assertOk();
        $this->postJson("/api/quotations/{$approved->id}/approve", [], $this->h($owner))->assertOk();
        $this->postJson("/api/quotations/{$approved->id}/accept", [], $this->h($owner))->assertStatus(409);
        $this->postJson("/api/quotations/{$approved->id}/reject", [], $this->h($owner))
            ->assertOk()->assertJsonPath('status', 'rejected');

        $pending = $this->draft();
        $this->postJson("/api/quotations/{$pending->id}/submit", [], $this->h($owner))->assertOk();
        $this->postJson("/api/quotations/{$pending->id}/reject", [], $this->h($owner))
            ->assertOk()->assertJsonPath('status', 'rejected');
        // And nothing moves a rejected quotation on.
        $this->postJson("/api/quotations/{$pending->id}/submit", [], $this->h($owner))->assertStatus(409);
    }

    // ── To revise (2026-09-15) ──────────────────────────────────────

    private function sent(): Quotation
    {
        $owner = $this->tokenFor(User::ROLE_OWNER);
        $q = $this->draft();
        foreach (['submit', 'approve', 'send'] as $step) {
            $this->postJson("/api/quotations/{$q->id}/{$step}", [], $this->h($owner))->assertOk();
        }

        return $q->fresh();
    }

    public function test_a_sent_quotation_can_come_back_to_revise_and_only_from_sent(): void
    {
        $owner = $this->tokenFor(User::ROLE_OWNER);
        $q = $this->sent();

        $this->postJson("/api/quotations/{$q->id}/to-revise", [], $this->h($owner))->assertStatus(422);
        $this->postJson("/api/quotations/{$q->id}/to-revise", ['reason' => 'Customer wants 30 hours, not 20'], $this->h($owner))
            ->assertOk()->assertJsonPath('status', 'to_revise')
            ->assertJsonPath('revision_reason', 'Customer wants 30 hours, not 20')
            ->assertJsonPath('revision_id', null);
        $this->assertNotNull($q->fresh()->to_revise_at);

        // Not from a draft, and not twice.
        $draft = $this->draft();
        $this->postJson("/api/quotations/{$draft->id}/to-revise", ['reason' => 'x'], $this->h($owner))->assertStatus(409);
        $this->postJson("/api/quotations/{$q->id}/to-revise", ['reason' => 'x'], $this->h($owner))->assertStatus(409);
        // Nor accepted from to_revise: the revision is what gets accepted.
        $this->postJson("/api/quotations/{$q->id}/accept", [], $this->h($owner))->assertStatus(409);
        // But it can still be rejected outright.
        $this->postJson("/api/quotations/{$q->id}/reject", [], $this->h($owner))->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function test_a_revision_is_a_new_draft_copy_linked_back_that_goes_through_approval_again(): void
    {
        $owner = $this->tokenFor(User::ROLE_OWNER);
        $q = $this->sent();
        $line = $q->lines()->create([
            'description' => 'Support', 'unit_of_measure' => 'Hours', 'quantity' => '20.00',
            'unit_price_sgd' => '150.00', 'line_total_sgd' => '3000.00',
        ]);
        $this->postJson("/api/quotations/{$q->id}/to-revise", ['reason' => '30 hours please'], $this->h($owner))->assertOk();

        $rev = $this->postJson("/api/quotations/{$q->id}/revise", [], $this->h($owner))->assertOk();
        $this->assertSame('draft', $rev->json('status'));
        // (Factory-made originals take their number from a throwaway company, so
        // compare identities, not numbers -- the API-raised revision's number is real.)
        $this->assertNotSame($q->id, $rev->json('id'));
        $this->assertSame($q->id, $rev->json('revised_from_quotation_id'));
        $this->assertSame($q->quotation_number, $rev->json('revised_from_quotation_number'));
        $this->assertSame($q->customer_id, $rev->json('customer_id'));
        $this->assertCount(1, $rev->json('lines'));
        $this->assertSame($line->description, $rev->json('lines.0.description'));
        $this->assertEquals(3000, $rev->json('amount_sgd'));

        // The original now points at its revision, and cannot be revised twice while it is open.
        $this->getJson("/api/quotations/{$q->id}", $this->h($owner))->assertOk()
            ->assertJsonPath('status', 'to_revise')
            ->assertJsonPath('revision_id', $rev->json('id'))
            ->assertJsonPath('revision_status', 'draft');
        $this->postJson("/api/quotations/{$q->id}/revise", [], $this->h($owner))->assertStatus(409);

        // The revision walks the normal path: no sending before approval.
        $this->postJson('/api/quotations/'.$rev->json('id').'/send', [], $this->h($owner))->assertStatus(409);
        $this->postJson('/api/quotations/'.$rev->json('id').'/submit', [], $this->h($owner))->assertOk()
            ->assertJsonPath('status', 'pending_approval');

        // Neither a to_revise original nor its draft revision counts as "pending confirmation by client".
        $this->getJson('/api/sales-dashboard/summary', $this->h($owner))->assertOk()
            ->assertJsonPath('quotations_pending_confirmation.count', 0)
            ->assertJsonPath('quotations_pending_approval.count', 1);
    }

    public function test_the_sales_dashboard_counts_pending_approval_and_pending_client_confirmation(): void
    {
        $owner = $this->tokenFor(User::ROLE_OWNER);
        $a = $this->draft();
        $b = $this->draft();
        $c = $this->draft();
        $this->draft(); // stays draft: counted in neither
        foreach ([$a, $b, $c] as $q) {
            $this->postJson("/api/quotations/{$q->id}/submit", [], $this->h($owner))->assertOk();
        }
        $this->postJson("/api/quotations/{$c->id}/approve", [], $this->h($owner))->assertOk();
        $this->postJson("/api/quotations/{$c->id}/send", [], $this->h($owner))->assertOk();

        $this->getJson('/api/sales-dashboard/summary', $this->h($owner))->assertOk()
            ->assertJsonPath('quotations_pending_approval.count', 2)
            ->assertJsonPath('quotations_pending_approval.not_available', false)
            ->assertJsonPath('quotations_pending_confirmation.count', 1)
            ->assertJsonPath('quotations_pending_confirmation.not_available', false);
    }
}
