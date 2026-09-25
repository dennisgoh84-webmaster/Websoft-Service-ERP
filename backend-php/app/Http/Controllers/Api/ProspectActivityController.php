<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Prospect Activities -- calls, emails, meetings and so on, each logged
 * against a Prospect (mostly by the salesperson on the Mobile App). A
 * user sees the activities on prospects they can see, plus any they
 * logged themselves.
 *
 * An activity is never deleted (Dennis, 2026-09-26): a mistaken one is
 * voided with a reason and stays on record as VOID, no longer editable.
 */
class ProspectActivityController extends Controller
{
    private const MODULE = 'prospects';

    private const TYPES = 'call,email,meeting,note,follow_up,proposal,demo,negotiation';

    private const STATUSES = 'planned,completed,pending,cancelled';

    private function visibleTo(User $user): Builder
    {
        $query = ProspectActivity::where('company_id', $user->company_id);
        if (! $user->seesAllProspects()) {
            $mine = Prospect::visibleTo($user)->select('id');
            $query->where(fn (Builder $q) => $q->where('created_by_user_id', $user->id)->orWhereIn('prospect_id', $mine));
        }

        return $query;
    }

    private function activityOrFail(User $user, string $activityId): ProspectActivity
    {
        $activity = $this->visibleTo($user)->find($activityId);
        if (! $activity) {
            throw new ApiException(404, 'Prospect activity not found');
        }

        return $activity;
    }

    private function present(ProspectActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'company_id' => $activity->company_id,
            'prospect_id' => $activity->prospect_id,
            'prospect_number' => $activity->prospect?->prospect_number,
            'prospect_title' => $activity->prospect?->title,
            'customer_id' => $activity->customer_id,
            'customer_name' => $activity->customer?->name,
            'activity_type' => $activity->activity_type,
            'subject' => $activity->subject,
            'description' => $activity->description,
            'activity_date' => optional($activity->activity_date)->toJSON(),
            'status' => $activity->status,
            'created_by_user_id' => $activity->created_by_user_id,
            'created_by_name' => $activity->createdBy?->full_name,
            'last_edited_by_user_id' => $activity->last_edited_by_user_id,
            'last_edited_by_name' => $activity->lastEditedBy?->full_name,
            'void_reason' => $activity->void_reason,
            'voided_at' => optional($activity->voided_at)->toJSON(),
            'voided_by_name' => $activity->voidedBy?->full_name,
            'created_at' => optional($activity->created_at)->toJSON(),
            'updated_at' => optional($activity->updated_at)->toJSON(),
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = $this->visibleTo($user)->with(['prospect', 'customer', 'createdBy', 'lastEditedBy']);
        foreach (['prospect_id', 'customer_id', 'activity_type', 'status', 'created_by_user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        return $query->orderByDesc('activity_date')->get()->map(fn ($a) => $this->present($a))->values();
    }

    public function show(Request $request, string $activityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->activityOrFail($user, $activityId)));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'prospect_id' => 'required|uuid',
            'activity_type' => 'required|in:'.self::TYPES,
            'subject' => 'required|string|min:1|max:255',
            'description' => 'sometimes|nullable|string',
            'activity_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'status' => 'sometimes|in:'.self::STATUSES,
        ]);

        $prospect = Prospect::find($data['prospect_id']);
        if (! $prospect || ! $prospect->isVisibleTo($user)) {
            throw new ApiException(404, 'Prospect not found');
        }

        $activity = ProspectActivity::create([
            'company_id' => $user->company_id,
            'prospect_id' => $prospect->id,
            'customer_id' => $prospect->customer_id,
            'activity_type' => $data['activity_type'],
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'activity_date' => $data['activity_date'] ?? now(),
            'status' => $data['status'] ?? ProspectActivity::STATUS_COMPLETED,
            'created_by_user_id' => $user->id,
            'last_edited_by_user_id' => $user->id,
        ]);

        Audit::record(
            entityType: 'prospect_activity',
            entityId: $activity->id,
            action: 'created',
            actorUserId: $user->id,
            companyId: $user->company_id,
            newValue: [
                'prospect_id' => $prospect->id,
                'activity_type' => $activity->activity_type,
                'subject' => $activity->subject,
            ],
        );

        return response()->json($this->present($activity->fresh()));
    }

    public function update(Request $request, string $activityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $activity = $this->activityOrFail($user, $activityId);
        $this->refuseIfVoid($activity);

        $data = $request->validate([
            'activity_type' => 'sometimes|in:'.self::TYPES,
            'subject' => 'sometimes|string|min:1|max:255',
            'description' => 'sometimes|nullable|string',
            'activity_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'status' => 'sometimes|in:'.self::STATUSES,
        ]);

        $oldData = [];
        $newData = [];
        foreach (['activity_type', 'subject', 'description', 'activity_date', 'status'] as $field) {
            if (isset($data[$field]) && $data[$field] !== $activity->{$field}) {
                $oldData[$field] = $activity->{$field};
                $newData[$field] = $data[$field];
            }
        }

        if ($newData !== []) {
            $activity->fill($data);
            $activity->last_edited_by_user_id = $user->id;
            $activity->updated_at = Carbon::now();
            $activity->save();
            Audit::record(
                entityType: 'prospect_activity',
                entityId: $activity->id,
                action: 'updated',
                actorUserId: $user->id,
                companyId: $user->company_id,
                oldValue: $oldData,
                newValue: $newData,
            );
        }

        return response()->json($this->present($activity->fresh()));
    }

    public function void(Request $request, string $activityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $activity = $this->activityOrFail($user, $activityId);
        $this->refuseIfVoid($activity);

        $data = $request->validate(['reason' => 'required|string|min:1|max:1000']);
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw new ApiException(422, 'A reason is required to void an activity.');
        }

        $oldStatus = $activity->status;
        $activity->status = ProspectActivity::STATUS_VOID;
        $activity->void_reason = $reason;
        $activity->voided_at = Carbon::now();
        $activity->voided_by_user_id = $user->id;
        $activity->last_edited_by_user_id = $user->id;
        $activity->updated_at = Carbon::now();
        $activity->save();

        Audit::record(
            entityType: 'prospect_activity',
            entityId: $activity->id,
            action: 'voided',
            actorUserId: $user->id,
            companyId: $user->company_id,
            details: $reason,
            oldValue: ['status' => $oldStatus],
            newValue: ['status' => ProspectActivity::STATUS_VOID, 'void_reason' => $reason],
        );

        return response()->json($this->present($activity->fresh()));
    }

    private function refuseIfVoid(ProspectActivity $activity): void
    {
        if ($activity->status === ProspectActivity::STATUS_VOID) {
            throw new ApiException(409, 'This activity is VOID and can no longer be changed.');
        }
    }
}
