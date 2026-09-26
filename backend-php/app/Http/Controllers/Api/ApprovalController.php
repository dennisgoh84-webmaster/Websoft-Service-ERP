<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\ApprovalError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\ApprovalAuthority;
use App\Models\ApprovalAuthorityMember;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\BankAccount;
use App\Models\DocumentAttachment;
use App\Models\GroupModuleAuthority;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * eApproval Master. Mirrors backend/app/routers/approvals.py 1:1
 * (planned-work.md #4).
 *
 * Gated on `core_administration`: defining WHO may approve is an
 * administrative act, so configuring authorities and rules needs FULL,
 * while submitting and deciding need EDIT and reading needs VIEW.
 *
 * Entity types reuse App\Models\DocumentAttachment::ENTITY_TYPES rather
 * than declaring a second list -- Python shares one DocumentEntityType
 * enum between attachments and approvals for the same reason.
 */
class ApprovalController extends Controller
{
    private const MODULE = 'core_administration';

    // ── Authorities ─────────────────────────────────────────────────

    public function listAuthorities(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);

        return response()->json(
            ApprovalAuthority::with(['members', 'rules'])
                ->where('company_id', $user->company_id)
                ->orderBy('name')->get()
                ->map(fn (ApprovalAuthority $a) => $this->authorityOut($a))
        );
    }

    public function getAuthority(Request $request, string $authorityId)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);

        return response()->json($this->authorityOut($this->authorityOrFail($user->company_id, $authorityId)));
    }

    public function createAuthority(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);

        $data = $request->validate([
            'name' => 'required|string|min:1|max:200',
            'description' => 'sometimes|nullable|string|max:500',
            'mode' => 'sometimes|string|in:'.implode(',', ApprovalAuthority::MODES),
            'bank_account_id' => 'sometimes|nullable|uuid',
        ]);
        $this->assertBankAccount($user->company_id, $data['bank_account_id'] ?? null);

        $authority = DB::transaction(function () use ($data, $user) {
            $authority = ApprovalAuthority::create($data + ['company_id' => $user->company_id]);

            Audit::record(
                entityType: 'approval_authority',
                entityId: $authority->id,
                action: 'approval_authority_create',
                actorUserId: $user->id,
                details: "{$authority->name} ({$authority->mode})",
                newValue: ['name' => $authority->name, 'mode' => $authority->mode],
            );

            return $authority;
        });

        return response()->json($this->authorityOut($this->authorityOrFail($user->company_id, $authority->id)), 201);
    }

    public function updateAuthority(Request $request, string $authorityId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);

        $data = $request->validate([
            'name' => 'sometimes|string|min:1|max:200',
            'description' => 'sometimes|nullable|string|max:500',
            'mode' => 'sometimes|string|in:'.implode(',', ApprovalAuthority::MODES),
            'bank_account_id' => 'sometimes|nullable|uuid',
            'is_active' => 'sometimes|boolean',
        ]);
        $authority = $this->authorityOrFail($user->company_id, $authorityId);
        $this->assertBankAccount($user->company_id, $data['bank_account_id'] ?? null);

        DB::transaction(function () use ($data, $authority, $user) {
            $authority->fill($data)->save();

            Audit::record(
                entityType: 'approval_authority',
                entityId: $authority->id,
                action: 'approval_authority_update',
                actorUserId: $user->id,
                details: $authority->name,
                newValue: array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $data),
            );
        });

        return response()->json($this->authorityOut($this->authorityOrFail($user->company_id, $authorityId)));
    }

    // ── Members ─────────────────────────────────────────────────────

    public function addMember(Request $request, string $authorityId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);
        $data = $request->validate(['user_id' => 'required|uuid']);
        $authority = $this->authorityOrFail($user->company_id, $authorityId);

        // An approver must be a user of this company -- Python does not
        // check this, so it is deliberate hardening, consistent with the
        // Stock and Software Tasks conversions.
        if (! User::where('company_id', $user->company_id)->whereKey($data['user_id'])->exists()) {
            throw new ApiException(404, 'User not found');
        }

        if (ApprovalAuthorityMember::where('authority_id', $authority->id)
            ->where('user_id', $data['user_id'])->exists()) {
            throw new ApiException(409, 'User is already a member of this authority');
        }

        $member = DB::transaction(function () use ($authority, $data, $user) {
            $member = ApprovalAuthorityMember::create([
                'authority_id' => $authority->id,
                'user_id' => $data['user_id'],
            ]);

            Audit::record(
                entityType: 'approval_authority',
                entityId: $authority->id,
                action: 'approval_member_add',
                actorUserId: $user->id,
                details: $authority->name,
                newValue: ['member_user_id' => $data['user_id']],
            );

            return $member;
        });

        return response()->json($this->memberOut($member), 201);
    }

    public function removeMember(Request $request, string $authorityId, string $memberId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);
        $authority = $this->authorityOrFail($user->company_id, $authorityId);

        $member = ApprovalAuthorityMember::where('authority_id', $authority->id)->find($memberId);
        if (! $member) {
            throw new ApiException(404, 'Member not found');
        }

        DB::transaction(function () use ($member, $authority, $user) {
            Audit::record(
                entityType: 'approval_authority',
                entityId: $authority->id,
                action: 'approval_member_remove',
                actorUserId: $user->id,
                details: $authority->name,
                oldValue: ['member_user_id' => $member->user_id],
            );
            // Membership is configuration, not a financial record, so it
            // is really removed -- decisions they already made stay.
            $member->delete();
        });

        return response()->noContent();
    }

    // ── Rules ───────────────────────────────────────────────────────

    public function createRule(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);

        $data = $request->validate([
            'authority_id' => 'required|uuid',
            'entity_type' => 'required|string|in:'.implode(',', DocumentAttachment::ENTITY_TYPES),
            'threshold_amount' => 'sometimes|nullable|numeric',
            'priority' => 'sometimes|integer',
        ]);
        $this->authorityOrFail($user->company_id, $data['authority_id']);

        $rule = DB::transaction(function () use ($data, $user) {
            $rule = ApprovalRule::create($data);

            Audit::record(
                entityType: 'approval_rule',
                entityId: $rule->id,
                action: 'approval_rule_create',
                actorUserId: $user->id,
                details: "{$rule->entity_type} threshold=".($rule->threshold_amount ?? 'any'),
                newValue: [
                    'authority_id' => $rule->authority_id,
                    'entity_type' => $rule->entity_type,
                    'threshold_amount' => $rule->threshold_amount === null ? null : (string) $rule->threshold_amount,
                ],
            );

            return $rule;
        });

        return response()->json($this->ruleOut($rule->refresh()), 201);
    }

    public function updateRule(Request $request, string $ruleId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);

        $data = $request->validate([
            'entity_type' => 'sometimes|string|in:'.implode(',', DocumentAttachment::ENTITY_TYPES),
            'threshold_amount' => 'sometimes|nullable|numeric',
            'priority' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $rule = ApprovalRule::find($ruleId);
        if (! $rule) {
            throw new ApiException(404, 'Rule not found');
        }
        // The rule is reached through its authority, which is what
        // carries the company -- so ownership is checked there.
        $this->authorityOrFail($user->company_id, $rule->authority_id);

        DB::transaction(function () use ($data, $rule, $user) {
            $rule->fill($data)->save();

            Audit::record(
                entityType: 'approval_rule',
                entityId: $rule->id,
                action: 'approval_rule_update',
                actorUserId: $user->id,
                details: $rule->entity_type,
                newValue: array_map(fn ($v) => $v === null ? null : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), $data),
            );
        });

        return response()->json($this->ruleOut($rule->refresh()));
    }

    public function deleteRule(Request $request, string $ruleId)
    {
        $user = $this->at($request, GroupModuleAuthority::FULL);

        $rule = ApprovalRule::find($ruleId);
        if (! $rule) {
            throw new ApiException(404, 'Rule not found');
        }
        $this->authorityOrFail($user->company_id, $rule->authority_id);

        DB::transaction(function () use ($rule, $user) {
            Audit::record(
                entityType: 'approval_rule',
                entityId: $rule->id,
                action: 'approval_rule_delete',
                actorUserId: $user->id,
                details: $rule->entity_type,
                oldValue: ['entity_type' => $rule->entity_type],
            );
            $rule->delete();
        });

        return response()->noContent();
    }

    // ── Submit / decide / read ──────────────────────────────────────

    public function submit(Request $request)
    {
        $user = $this->at($request, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'entity_type' => 'required|string|in:'.implode(',', DocumentAttachment::ENTITY_TYPES),
            'entity_id' => 'required|uuid',
            'amount' => 'sometimes|nullable|numeric',
        ]);

        $requests = DB::transaction(fn () => ApprovalService::submitForApproval(
            $user->company_id,
            $data['entity_type'],
            $data['entity_id'],
            $user->id,
            $data['amount'] ?? null,
        ));

        // An empty list is a valid answer, not an error: it means no
        // rule covers this document, so it needs no approval.
        return response()->json($requests->map(fn (ApprovalRequest $r) => $this->requestOut($r)));
    }

    public function decide(Request $request, string $requestId)
    {
        // Any signed-in approver (Backlog 2): being a member of the
        // request's authority is the permission, checked by
        // recordDecision -- approvers need no Core Administration access.
        $user = Authenticate::user($request);

        $data = $request->validate([
            'decision' => 'required|string|in:'.implode(',', ApprovalDecision::VALUES),
            'comment' => 'sometimes|nullable|string|max:1000',
        ]);

        // Company scoping first, so another company's request is a 404
        // rather than leaking its existence through an approval error.
        $existing = ApprovalRequest::where('company_id', $user->company_id)->find($requestId);
        if (! $existing) {
            throw new ApiException(404, 'Approval request not found.');
        }
        if ($existing->entity_type === 'service_record') {
            throw new ApiException(422, 'Approve or reject a Service Record on the Service Record Approval screen, where the hours to deduct are keyed in.');
        }

        try {
            $resolved = DB::transaction(fn () => ApprovalService::recordDecision(
                $requestId, $user->id, $data['decision'], $data['comment'] ?? null
            ));
        } catch (ApprovalError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        Audit::record(
            entityType: 'approval_request',
            entityId: $resolved->id,
            action: 'approval_decision',
            actorUserId: $user->id,
            details: "{$resolved->entity_type} {$data['decision']} -- request now {$resolved->status}",
            newValue: ['decision' => $data['decision'], 'status' => $resolved->status],
        );

        return response()->json($this->requestOut($resolved->load('decisions')));
    }

    public function listPending(Request $request)
    {
        // Each person sees only what waits on their own authorities.
        $user = Authenticate::user($request);

        return response()->json(
            ApprovalService::listPendingForUser($user->company_id, $user->id)
                ->map(fn (ApprovalRequest $r) => $this->requestOut($r))
        );
    }

    public function listForEntity(Request $request, string $entityType, string $entityId)
    {
        $user = $this->at($request, GroupModuleAuthority::VIEW);

        if (! in_array($entityType, DocumentAttachment::ENTITY_TYPES, true)) {
            throw new ApiException(422, "Invalid entity type: {$entityType}");
        }

        return response()->json(
            ApprovalService::listRequestsForEntity($user->company_id, $entityType, $entityId)
                ->map(fn (ApprovalRequest $r) => $this->requestOut($r))
        );
    }

    // ── Shared ──────────────────────────────────────────────────────

    private function at(Request $request, string $level): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, $level);

        return $user;
    }

    private function authorityOrFail(string $companyId, string $authorityId): ApprovalAuthority
    {
        $authority = ApprovalAuthority::with(['members', 'rules'])
            ->where('company_id', $companyId)->find($authorityId);
        if (! $authority) {
            throw new ApiException(404, 'Approval authority not found');
        }

        return $authority;
    }

    private function assertBankAccount(string $companyId, ?string $bankAccountId): void
    {
        if ($bankAccountId === null) {
            return;
        }
        if (! BankAccount::where('company_id', $companyId)->whereKey($bankAccountId)->exists()) {
            throw new ApiException(404, 'Bank account not found');
        }
    }

    /** @return array<string, mixed> */
    private function authorityOut(ApprovalAuthority $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'description' => $a->description,
            'mode' => $a->mode,
            'bank_account_id' => $a->bank_account_id,
            'is_active' => $a->is_active,
            'created_at' => $a->created_at?->toJSON(),
            'members' => $a->members->map(fn (ApprovalAuthorityMember $m) => $this->memberOut($m))->values(),
            'rules' => $a->rules->map(fn (ApprovalRule $r) => $this->ruleOut($r))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function memberOut(ApprovalAuthorityMember $m): array
    {
        return [
            'id' => $m->id,
            'authority_id' => $m->authority_id,
            'user_id' => $m->user_id,
            'added_at' => $m->added_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function ruleOut(ApprovalRule $r): array
    {
        return [
            'id' => $r->id,
            'authority_id' => $r->authority_id,
            'entity_type' => $r->entity_type,
            // Python types this as float, and null means "any value".
            'threshold_amount' => $r->threshold_amount === null ? null : (float) $r->threshold_amount,
            'priority' => $r->priority,
            'is_active' => $r->is_active,
            'created_at' => $r->created_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function requestOut(ApprovalRequest $r): array
    {
        return [
            'id' => $r->id,
            'entity_type' => $r->entity_type,
            'entity_id' => $r->entity_id,
            'rule_id' => $r->rule_id,
            'authority_id' => $r->authority_id,
            'status' => $r->status,
            'requested_by_user_id' => $r->requested_by_user_id,
            'summary' => $r->summary,
            'requested_at' => $r->requested_at?->toJSON(),
            'resolved_at' => $r->resolved_at?->toJSON(),
            'decisions' => $r->decisions->map(fn (ApprovalDecision $d) => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'decision' => $d->decision,
                'comment' => $d->comment,
                'decided_at' => $d->decided_at?->toJSON(),
            ])->values(),
        ];
    }
}
