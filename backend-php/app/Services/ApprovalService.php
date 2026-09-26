<?php

namespace App\Services;

use App\Exceptions\ApprovalError;
use App\Models\ApprovalAuthority;
use App\Models\ApprovalAuthorityMember;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\User;
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
        ?string $bankAccountId = null,
    ): Collection {
        $rules = ApprovalRule::query()
            ->join('approval_authorities', 'approval_rules.authority_id', '=', 'approval_authorities.id')
            ->where('approval_authorities.company_id', $companyId)
            ->where('approval_authorities.is_active', true)
            ->where('approval_rules.is_active', true)
            ->where('approval_rules.entity_type', $entityType)
            // A Bank Authority (Backlog 2, 2026-09-26) is the signatories of
            // one bank account: a Payment Voucher goes to the authority of
            // the account it is paid from, or to one set for every account.
            ->when($entityType === 'payment_voucher', fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('approval_authorities.bank_account_id')
                ->when($bankAccountId !== null, fn ($x) => $x->orWhere('approval_authorities.bank_account_id', $bankAccountId))))
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
        ?string $bankAccountId = null,
        ?string $summary = null,
    ): Collection {
        $requests = collect();

        foreach (self::findApplicableRules($companyId, $entityType, $amount, $bankAccountId) as $rule) {
            $existing = ApprovalRequest::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('rule_id', $rule->id)
                ->where('status', ApprovalRequest::STATUS_PENDING)
                ->first();
            if ($existing) {
                $requests->push($existing);

                continue;
            }

            $created = ApprovalRequest::create([
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'rule_id' => $rule->id,
                'authority_id' => $rule->authority_id,
                'requested_by_user_id' => $requestedByUserId,
                'summary' => $summary !== null ? mb_substr($summary, 0, 300) : null,
            ]);
            // "Email as each item arrives" (Backlog 2, 2026-09-26).
            ApprovalNotifier::requested($created);
            $requests->push($created);
        }

        return $requests;
    }

    /**
     * Where one document stands: null when it never needed approval,
     * 'pending' while any request is waiting, 'rejected' once any was
     * rejected, else 'approved'.
     */
    public static function stateOf(string $companyId, string $entityType, string $entityId): ?string
    {
        $statuses = ApprovalRequest::where('company_id', $companyId)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->pluck('status');
        if ($statuses->isEmpty()) {
            return null;
        }
        if ($statuses->contains(ApprovalRequest::STATUS_PENDING)) {
            return ApprovalRequest::STATUS_PENDING;
        }

        return $statuses->contains(ApprovalRequest::STATUS_REJECTED) ? ApprovalRequest::STATUS_REJECTED : ApprovalRequest::STATUS_APPROVED;
    }

    /** One line on where a document stands, for the screen and for a refusal: who it waits for, or who rejected it and why. */
    public static function describeState(string $companyId, string $entityType, string $entityId): ?string
    {
        $state = self::stateOf($companyId, $entityType, $entityId);
        if ($state === null) {
            return null;
        }
        $requests = ApprovalRequest::with('decisions')->where('company_id', $companyId)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->get();
        $authorities = ApprovalAuthority::whereIn('id', $requests->pluck('authority_id'))->pluck('name', 'id');
        if ($state === ApprovalRequest::STATUS_PENDING) {
            $names = $requests->where('status', ApprovalRequest::STATUS_PENDING)->map(fn ($r) => $authorities->get($r->authority_id))->filter()->unique()->implode(', ');

            return "Waiting for approval by {$names}.";
        }
        if ($state === ApprovalRequest::STATUS_REJECTED) {
            $rejection = $requests->flatMap(fn ($r) => $r->decisions)->firstWhere('decision', ApprovalDecision::REJECTED);
            $who = $rejection ? (User::whereKey($rejection->user_id)->value('full_name') ?? 'an approver') : 'an approver';

            return "Rejected by {$who}".($rejection?->comment ? ": {$rejection->comment}" : '').'.';
        }
        $who = $requests->flatMap(fn ($r) => $r->decisions)->where('decision', ApprovalDecision::APPROVED)
            ->map(fn ($d) => User::whereKey($d->user_id)->value('full_name'))->filter()->unique()->implode(', ');

        return 'Approved'.($who !== '' ? " by {$who}" : '').'.';
    }

    /**
     * A decision taken on the document's own screen (a Service Record,
     * approved with its hours or rejected there): recorded on every
     * pending request for it, which it settles -- any one approver
     * decides a Service Record.
     */
    public static function settleFromDocument(string $companyId, string $entityType, string $entityId, string $userId, string $decision, ?string $comment = null): void
    {
        $pending = ApprovalRequest::with('decisions')->where('company_id', $companyId)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->where('status', ApprovalRequest::STATUS_PENDING)->get();
        foreach ($pending as $request) {
            if ($request->decisions->firstWhere('user_id', $userId) === null) {
                ApprovalDecision::create(['request_id' => $request->id, 'user_id' => $userId, 'decision' => $decision, 'comment' => $comment]);
            }
            $request->status = $decision === ApprovalDecision::REJECTED ? ApprovalRequest::STATUS_REJECTED : ApprovalRequest::STATUS_APPROVED;
            $request->resolved_at = Carbon::now();
            $request->save();
        }
    }

    /** @return Collection<int, string> ids of the active authorities with an active rule for this document type */
    public static function authoritiesFor(string $companyId, string $entityType): Collection
    {
        return ApprovalRule::query()
            ->join('approval_authorities', 'approval_rules.authority_id', '=', 'approval_authorities.id')
            ->where('approval_authorities.company_id', $companyId)
            ->where('approval_authorities.is_active', true)
            ->where('approval_rules.is_active', true)
            ->where('approval_rules.entity_type', $entityType)
            ->pluck('approval_authorities.id')->unique()->values();
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
        $request->refresh();
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            ApprovalOutcomes::resolved($request, $userId, $comment);
        }

        return $request;
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
            $request->resolved_at = Carbon::now();
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
            $request->resolved_at = Carbon::now();
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
