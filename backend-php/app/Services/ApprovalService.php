<?php

namespace App\Services;

use App\Exceptions\ApprovalError;
use App\Models\ApprovalAuthority;
use App\Models\ApprovalAuthorityMember;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * eApproval Master business logic. Mirrors
 * backend/app/services/approvals.py 1:1 (planned-work.md #4).
 *
 * The generic framework that replaces one-off approval logic: an
 * authority names who may approve, a rule binds that authority to a
 * document type above an optional value threshold, and a request is one
 * document actually waiting.
 */
class ApprovalService
{
    /**
     * Active rules matching this document type, and -- when an amount is
     * given -- whose threshold that amount reaches.
     *
     * NOTE THE ASYMMETRY, faithful to Python: called WITHOUT an amount,
     * only rules with NO threshold match. A thresholded rule is not
     * "matched by default" when the caller cannot say what the document
     * is worth, which fails safe in the sense that matters -- it never
     * silently approves, it just does not raise that request.
     *
     * @return Collection<int, ApprovalRule>
     */
    public static function findApplicableRules(
        string $companyId,
        string $entityType,
        string|int|float|null $amount = null,
    ): Collection {
        $rules = ApprovalRule::query()
            ->join('approval_authorities', 'approval_rules.authority_id', '=', 'approval_authorities.id')
            ->where('approval_authorities.company_id', $companyId)
            ->where('approval_authorities.is_active', true)
            ->where('approval_rules.is_active', true)
            ->where('approval_rules.entity_type', $entityType)
            ->orderBy('approval_rules.priority')
            ->select('approval_rules.*')
            ->get();

        if ($amount === null) {
            return $rules->filter(fn (ApprovalRule $r) => $r->threshold_amount === null)->values();
        }

        $value = Money::of($amount);

        return $rules->filter(function (ApprovalRule $r) use ($value) {
            if ($r->threshold_amount === null) {
                return true;
            }

            return $value->toFloat() >= Money::of($r->threshold_amount)->toFloat();
        })->values();
    }

    /**
     * Submit a document under every applicable rule. Returns one request
     * per matching rule, or an empty list when the document needs no
     * approval at all.
     *
     * Re-submitting is idempotent: an existing PENDING request for the
     * same document and rule is returned rather than duplicated, so a
     * double click does not create two things to approve.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public static function submitForApproval(
        string $companyId,
        string $entityType,
        string $entityId,
        string $requestedByUserId,
        string|int|float|null $amount = null,
    ): Collection {
        $requests = collect();

        foreach (self::findApplicableRules($companyId, $entityType, $amount) as $rule) {
            $existing = ApprovalRequest::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('rule_id', $rule->id)
                ->where('status', ApprovalRequest::STATUS_PENDING)
                ->first();

            $requests->push($existing ?? ApprovalRequest::create([
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'rule_id' => $rule->id,
                'authority_id' => $rule->authority_id,
                'requested_by_user_id' => $requestedByUserId,
            ]));
        }

        return $requests;
    }

    /**
     * Record one approver's decision, then resolve the request if the
     * authority's mode is now satisfied.
     *
     * @throws ApprovalError when the request is missing or already
     *                       resolved, the user is not a member of the
     *                       authority, or they have already decided.
     */
    public static function recordDecision(
        string $requestId,
        string $userId,
        string $decision,
        ?string $comment = null,
    ): ApprovalRequest {
        $request = ApprovalRequest::with('decisions')->find($requestId);
        if ($request === null) {
            throw new ApprovalError('Approval request not found.');
        }
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw new ApprovalError("This request has already been {$request->status}.");
        }

        $isMember = ApprovalAuthorityMember::where('authority_id', $request->authority_id)
            ->where('user_id', $userId)->exists();
        if (! $isMember) {
            throw new ApprovalError('You are not assigned to this approval authority.');
        }

        if ($request->decisions->firstWhere('user_id', $userId) !== null) {
            throw new ApprovalError('You have already recorded a decision on this request.');
        }

        ApprovalDecision::create([
            'request_id' => $request->id,
            'user_id' => $userId,
            'decision' => $decision,
            'comment' => $comment,
        ]);

        self::resolve($request->load('decisions'));

        return $request->refresh();
    }

    /**
     * Resolve a request against its authority's mode.
     *
     * ANY REJECTION REJECTS THE WHOLE REQUEST IMMEDIATELY, whatever the
     * mode and however many approvals it already has -- one approver
     * saying no is not outvoted by others saying yes.
     */
    private static function resolve(ApprovalRequest $request): void
    {
        $authority = ApprovalAuthority::find($request->authority_id);
        if ($authority === null) {
            return;
        }

        $decisions = $request->decisions;

        if ($decisions->contains(fn (ApprovalDecision $d) => $d->decision === ApprovalDecision::REJECTED)) {
            $request->status = ApprovalRequest::STATUS_REJECTED;
            $request->resolved_at = Carbon::now('UTC');
            $request->save();

            return;
        }

        $approved = $decisions->where('decision', ApprovalDecision::APPROVED)->count();
        $memberCount = ApprovalAuthorityMember::where('authority_id', $authority->id)->count();

        $satisfied = $authority->mode === ApprovalAuthority::MODE_ANY_ONE
            ? $approved >= 1
            : $approved >= $memberCount;

        if ($satisfied) {
            $request->status = ApprovalRequest::STATUS_APPROVED;
            $request->resolved_at = Carbon::now('UTC');
            $request->save();
        }
    }

    /**
     * Pending requests this user can actually act on: they are a member
     * of the authority AND have not already decided. Someone who has
     * decided on an all_must request stops seeing it while it waits for
     * their colleagues.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public static function listPendingForUser(string $companyId, string $userId): Collection
    {
        $authorityIds = ApprovalAuthorityMember::where('user_id', $userId)->pluck('authority_id');
        if ($authorityIds->isEmpty()) {
            return collect();
        }

        return ApprovalRequest::with('decisions')
            ->where('company_id', $companyId)
            ->whereIn('authority_id', $authorityIds)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->orderBy('requested_at')->get()
            ->reject(fn (ApprovalRequest $r) => $r->decisions->firstWhere('user_id', $userId) !== null)
            ->values();
    }

    /**
     * Every request for one document, WHATEVER ITS STATUS -- the
     * retained-history view planned-work #4 asks for, where approved and
     * rejected items stay visible rather than disappearing.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public static function listRequestsForEntity(string $companyId, string $entityType, string $entityId): Collection
    {
        return ApprovalRequest::with('decisions')
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('requested_at')->get();
    }
}
