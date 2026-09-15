<?php

namespace Tests\Feature;

use App\Models\ApprovalAuthority;
use App\Models\ApprovalAuthorityMember;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\ApprovalService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\ApprovalController and
 * App\Services\ApprovalService -- eApproval Master, converted from
 * backend/app/routers/approvals.py (planned-work.md #4).
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'core_administration';

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->token = $this->ownerToken($this->company);
    }

    private function enableModule(Company $company, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Core / Administration', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company);

        return $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function tokenFor(User $user): string
    {
        return $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function approver(string $name): User
    {
        $this->enableModule($this->company);
        $group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::EDIT,
        ]);
        $user = User::factory()->for($this->company)->create([
            'full_name' => $name, 'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);

        return $user;
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function authority(string $mode = ApprovalAuthority::MODE_ANY_ONE, string $name = 'PO Approval'): ApprovalAuthority
    {
        return ApprovalAuthority::create([
            'company_id' => $this->company->id, 'name' => $name, 'mode' => $mode,
        ]);
    }

    private function rule(ApprovalAuthority $authority, ?float $threshold = null, string $entityType = 'purchase_order'): ApprovalRule
    {
        return ApprovalRule::create([
            'authority_id' => $authority->id, 'entity_type' => $entityType,
            'threshold_amount' => $threshold,
        ]);
    }

    // ── Configuration ───────────────────────────────────────────────

    public function test_an_authority_can_be_created_with_members_and_rules(): void
    {
        $created = $this->postJson('/api/approvals/authorities', [
            'name' => 'Bank Authority for DBS', 'mode' => ApprovalAuthority::MODE_ALL_MUST,
        ], $this->headers())->assertStatus(201)->json();

        $this->assertSame(ApprovalAuthority::MODE_ALL_MUST, $created['mode']);

        $alice = $this->approver('Alice Tan');
        $this->postJson("/api/approvals/authorities/{$created['id']}/members", [
            'user_id' => $alice->id,
        ], $this->headers())->assertStatus(201);

        $this->postJson('/api/approvals/rules', [
            'authority_id' => $created['id'], 'entity_type' => 'payment_voucher', 'threshold_amount' => 5000,
        ], $this->headers())->assertStatus(201);
        // PHP's json_encode writes 5000.0 as `5000`; both parse to the
        // same JS Number, so the value is what is asserted, not the
        // literal -- same nuance recorded for Tax Types' rate_percent.
        $rule = ApprovalRule::where('entity_type', 'payment_voucher')->firstOrFail();
        $this->assertEqualsWithDelta(5000.0, (float) $rule->threshold_amount, 0.001);

        $this->getJson("/api/approvals/authorities/{$created['id']}", $this->headers())
            ->assertOk()->assertJsonCount(1, 'members')->assertJsonCount(1, 'rules');
    }

    public function test_the_same_person_cannot_be_added_twice(): void
    {
        $authority = $this->authority();
        $alice = $this->approver('Alice Tan');

        $this->postJson("/api/approvals/authorities/{$authority->id}/members", ['user_id' => $alice->id], $this->headers())
            ->assertStatus(201);
        $this->postJson("/api/approvals/authorities/{$authority->id}/members", ['user_id' => $alice->id], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('detail', 'User is already a member of this authority');
    }

    public function test_a_user_from_another_company_cannot_be_made_an_approver(): void
    {
        $authority = $this->authority();
        $outsider = User::factory()->for(Company::factory()->create())->create();

        $this->postJson("/api/approvals/authorities/{$authority->id}/members", ['user_id' => $outsider->id], $this->headers())
            ->assertStatus(404);
    }

    public function test_another_companys_authority_is_not_found(): void
    {
        $other = Company::factory()->create();
        $theirs = ApprovalAuthority::create(['company_id' => $other->id, 'name' => 'Theirs']);

        $this->getJson("/api/approvals/authorities/{$theirs->id}", $this->headers())->assertStatus(404);
        $this->patchJson("/api/approvals/authorities/{$theirs->id}", ['name' => 'Hijacked'], $this->headers())
            ->assertStatus(404);
    }

    // ── Rule matching ───────────────────────────────────────────────

    public function test_a_threshold_rule_only_applies_at_or_above_its_amount(): void
    {
        $authority = $this->authority();
        $this->rule($authority, 5000.0);

        $this->assertCount(0, ApprovalService::findApplicableRules($this->company->id, 'purchase_order', 4999.99));
        // "At or above" -- the boundary itself matches.
        $this->assertCount(1, ApprovalService::findApplicableRules($this->company->id, 'purchase_order', 5000.00));
        $this->assertCount(1, ApprovalService::findApplicableRules($this->company->id, 'purchase_order', 9999.00));
    }

    public function test_without_an_amount_only_unthresholded_rules_match(): void
    {
        $authority = $this->authority();
        $this->rule($authority, 5000.0);
        $this->rule($authority, null);

        // Deliberate, and faithful to Python: a thresholded rule is not
        // matched by default when the caller cannot say what the
        // document is worth.
        $this->assertCount(1, ApprovalService::findApplicableRules($this->company->id, 'purchase_order'));
    }

    public function test_an_inactive_authority_or_rule_matches_nothing(): void
    {
        $authority = $this->authority();
        $rule = $this->rule($authority, null);

        $rule->update(['is_active' => false]);
        $this->assertCount(0, ApprovalService::findApplicableRules($this->company->id, 'purchase_order'));

        $rule->update(['is_active' => true]);
        $authority->update(['is_active' => false]);
        $this->assertCount(0, ApprovalService::findApplicableRules($this->company->id, 'purchase_order'));
    }

    // ── Submit ──────────────────────────────────────────────────────

    public function test_submitting_with_no_matching_rule_returns_an_empty_list(): void
    {
        // Not an error: it means the document needs no approval.
        $this->postJson('/api/approvals/submit', [
            'entity_type' => 'purchase_order', 'entity_id' => (string) Str::uuid(), 'amount' => 100,
        ], $this->headers())->assertOk()->assertExactJson([]);
    }

    public function test_resubmitting_the_same_document_does_not_duplicate_the_request(): void
    {
        $authority = $this->authority();
        $this->rule($authority, null);
        $entityId = (string) Str::uuid();

        $first = $this->postJson('/api/approvals/submit', [
            'entity_type' => 'purchase_order', 'entity_id' => $entityId,
        ], $this->headers())->assertOk()->json();
        $second = $this->postJson('/api/approvals/submit', [
            'entity_type' => 'purchase_order', 'entity_id' => $entityId,
        ], $this->headers())->assertOk()->json();

        $this->assertSame($first[0]['id'], $second[0]['id'], 'a double submit must not raise two approvals');
        $this->assertSame(1, ApprovalRequest::where('entity_id', $entityId)->count());
    }

    // ── Deciding ────────────────────────────────────────────────────

    private function submitOne(ApprovalAuthority $authority, string $entityId): ApprovalRequest
    {
        $this->rule($authority, null);

        return ApprovalService::submitForApproval(
            $this->company->id, 'purchase_order', $entityId, User::where('company_id', $this->company->id)->first()->id
        )->first();
    }

    public function test_any_one_mode_resolves_on_the_first_approval(): void
    {
        $authority = $this->authority(ApprovalAuthority::MODE_ANY_ONE);
        $alice = $this->approver('Alice Tan');
        $bob = $this->approver('Bob Lim');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $bob->id]);
        $request = $this->submitOne($authority, (string) Str::uuid());

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::APPROVED,
        ], $this->headers($this->tokenFor($alice)))->assertOk()
            ->assertJsonPath('status', ApprovalRequest::STATUS_APPROVED);

        $this->assertNotNull($request->fresh()->resolved_at);
    }

    public function test_all_must_mode_waits_for_every_member(): void
    {
        $authority = $this->authority(ApprovalAuthority::MODE_ALL_MUST);
        $alice = $this->approver('Alice Tan');
        $bob = $this->approver('Bob Lim');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $bob->id]);
        $request = $this->submitOne($authority, (string) Str::uuid());

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::APPROVED,
        ], $this->headers($this->tokenFor($alice)))->assertOk()
            ->assertJsonPath('status', ApprovalRequest::STATUS_PENDING);

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::APPROVED,
        ], $this->headers($this->tokenFor($bob)))->assertOk()
            ->assertJsonPath('status', ApprovalRequest::STATUS_APPROVED);
    }

    public function test_one_rejection_rejects_the_whole_request_even_in_all_must(): void
    {
        // A single no is not outvoted by others saying yes.
        $authority = $this->authority(ApprovalAuthority::MODE_ALL_MUST);
        $alice = $this->approver('Alice Tan');
        $bob = $this->approver('Bob Lim');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $bob->id]);
        $request = $this->submitOne($authority, (string) Str::uuid());

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::APPROVED,
        ], $this->headers($this->tokenFor($alice)))->assertOk();

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::REJECTED, 'comment' => 'Wrong supplier',
        ], $this->headers($this->tokenFor($bob)))->assertOk()
            ->assertJsonPath('status', ApprovalRequest::STATUS_REJECTED);
    }

    public function test_a_non_member_cannot_decide(): void
    {
        $authority = $this->authority();
        $stranger = $this->approver('Not A Member');
        $request = $this->submitOne($authority, (string) Str::uuid());

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::APPROVED,
        ], $this->headers($this->tokenFor($stranger)))->assertStatus(422)
            ->assertJsonPath('detail', 'You are not assigned to this approval authority.');
    }

    public function test_the_same_approver_cannot_decide_twice(): void
    {
        $authority = $this->authority(ApprovalAuthority::MODE_ALL_MUST);
        $alice = $this->approver('Alice Tan');
        $bob = $this->approver('Bob Lim');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $bob->id]);
        $request = $this->submitOne($authority, (string) Str::uuid());

        $token = $this->tokenFor($alice);
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => ApprovalDecision::APPROVED], $this->headers($token))
            ->assertOk();
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => ApprovalDecision::APPROVED], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('detail', 'You have already recorded a decision on this request.');
    }

    public function test_a_resolved_request_cannot_be_decided_again(): void
    {
        $authority = $this->authority(ApprovalAuthority::MODE_ANY_ONE);
        $alice = $this->approver('Alice Tan');
        $bob = $this->approver('Bob Lim');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $bob->id]);
        $request = $this->submitOne($authority, (string) Str::uuid());

        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => ApprovalDecision::APPROVED], $this->headers($this->tokenFor($alice)))
            ->assertOk();
        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => ApprovalDecision::REJECTED], $this->headers($this->tokenFor($bob)))
            ->assertStatus(422)
            ->assertJsonPath('detail', 'This request has already been approved.');
    }

    public function test_another_companys_request_is_404_not_an_approval_error(): void
    {
        $other = Company::factory()->create();
        $theirAuthority = ApprovalAuthority::create(['company_id' => $other->id, 'name' => 'Theirs']);
        $theirRule = ApprovalRule::create(['authority_id' => $theirAuthority->id, 'entity_type' => 'purchase_order']);
        $theirRequest = ApprovalRequest::create([
            'company_id' => $other->id, 'entity_type' => 'purchase_order', 'entity_id' => (string) Str::uuid(),
            'rule_id' => $theirRule->id, 'authority_id' => $theirAuthority->id,
            'requested_by_user_id' => User::factory()->for($other)->create()->id,
        ]);

        // 404 rather than "you are not assigned", which would leak that
        // the request exists.
        $this->postJson("/api/approvals/requests/{$theirRequest->id}/decide", [
            'decision' => ApprovalDecision::APPROVED,
        ], $this->headers())->assertStatus(404);
    }

    // ── Reading ─────────────────────────────────────────────────────

    public function test_pending_lists_only_what_i_can_still_act_on(): void
    {
        $authority = $this->authority(ApprovalAuthority::MODE_ALL_MUST);
        $alice = $this->approver('Alice Tan');
        $bob = $this->approver('Bob Lim');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $bob->id]);
        $request = $this->submitOne($authority, (string) Str::uuid());

        $aliceToken = $this->tokenFor($alice);
        $this->getJson('/api/approvals/pending', $this->headers($aliceToken))->assertOk()->assertJsonCount(1);

        $this->postJson("/api/approvals/requests/{$request->id}/decide", ['decision' => ApprovalDecision::APPROVED], $this->headers($aliceToken))
            ->assertOk();

        // Still pending overall, but Alice has done her part.
        $this->getJson('/api/approvals/pending', $this->headers($aliceToken))->assertOk()->assertJsonCount(0);
        $this->getJson('/api/approvals/pending', $this->headers($this->tokenFor($bob)))->assertOk()->assertJsonCount(1);
    }

    public function test_the_entity_view_keeps_resolved_requests_visible(): void
    {
        // planned-work #4's explicit requirement: an approved or
        // rejected item must NOT disappear from the screen.
        $authority = $this->authority(ApprovalAuthority::MODE_ANY_ONE);
        $alice = $this->approver('Alice Tan');
        ApprovalAuthorityMember::create(['authority_id' => $authority->id, 'user_id' => $alice->id]);
        $entityId = (string) Str::uuid();
        $request = $this->submitOne($authority, $entityId);

        $this->postJson("/api/approvals/requests/{$request->id}/decide", [
            'decision' => ApprovalDecision::REJECTED, 'comment' => 'Over budget',
        ], $this->headers($this->tokenFor($alice)))->assertOk();

        $body = $this->getJson("/api/approvals/entity/purchase_order/{$entityId}", $this->headers())
            ->assertOk()->json();

        $this->assertCount(1, $body);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $body[0]['status']);
        // The decision and its comment survive too.
        $this->assertSame('Over budget', $body[0]['decisions'][0]['comment']);
    }

    public function test_an_invalid_entity_type_is_422(): void
    {
        $this->getJson('/api/approvals/entity/not_a_document/'.Str::uuid(), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Invalid entity type: not_a_document');
    }

    public function test_a_bank_authority_must_name_this_companys_bank_account(): void
    {
        $theirBank = BankAccount::create([
            'company_id' => Company::factory()->create()->id, 'bank_name' => 'UOB',
            'account_name' => 'Theirs', 'account_number' => '9', 'opening_balance_sgd' => '0.00',
        ]);

        $this->postJson('/api/approvals/authorities', [
            'name' => 'Bank Authority', 'bank_account_id' => $theirBank->id,
        ], $this->headers())->assertStatus(404);
    }

    public function test_configuring_needs_full_while_deciding_needs_only_edit(): void
    {
        $alice = $this->approver('Alice Tan'); // EDIT level
        $token = $this->tokenFor($alice);

        $this->postJson('/api/approvals/authorities', ['name' => 'Nope'], $this->headers($token))->assertStatus(403);
        $this->postJson('/api/approvals/rules', [
            'authority_id' => (string) Str::uuid(), 'entity_type' => 'purchase_order',
        ], $this->headers($token))->assertStatus(403);

        // But submitting and reading are open at EDIT/VIEW.
        $this->postJson('/api/approvals/submit', [
            'entity_type' => 'purchase_order', 'entity_id' => (string) Str::uuid(),
        ], $this->headers($token))->assertOk();
        $this->getJson('/api/approvals/pending', $this->headers($token))->assertOk();
    }
}
