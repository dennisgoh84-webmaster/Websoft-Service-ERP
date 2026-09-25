<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\ProspectActivity;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    private const MODULE = 'crm';

    private function prospectActivityOrFail(string $companyId, string $activityId): ProspectActivity
    {
        $activity = ProspectActivity::find($activityId);
        if (! $activity || $activity->company_id !== $companyId) {
            throw new ApiException(404, 'Prospect activity not found');
        }

        return $activity;
    }

    private function canViewActivity(User $user, ProspectActivity $activity): bool
    {
        // Sales Manager can view all, Sales Staff can only view their own.
        if ($user->role === User::ROLE_OWNER || $user->role === User::ROLE_SALES_MANAGER) {
            return true;
        }

        return $activity->created_by_user_id === $user->id;
    }

    private function present(ProspectActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'company_id' => $activity->company_id,
            'customer_id' => $activity->customer_id,
            'activity_type' => $activity->activity_type,
            'subject' => $activity->subject,
            'description' => $activity->description,
            'activity_date' => optional($activity->activity_date)->toJSON(),
            'status' => $activity->status,
            'created_by_user_id' => $activity->created_by_user_id,
            'created_by_name' => $activity->createdBy?->full_name,
            'last_edited_by_user_id' => $activity->last_edited_by_user_id,
            'last_edited_by_name' => $activity->lastEditedBy?->full_name,
            'created_at' => optional($activity->created_at)->toJSON(),
            'updated_at' => optional($activity->updated_at)->toJSON(),
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = ProspectActivity::where('company_id', $user->company_id);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->query('activity_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('created_by_user_id')) {
            $query->where('created_by_user_id', $request->query('created_by_user_id'));
        }

        // Apply role-based filtering: Sales Staff can only see their own activities
        if ($user->role !== User::ROLE_OWNER && $user->role !== User::ROLE_SALES_MANAGER) {
            $query->where('created_by_user_id', $user->id);
        }

        return $query->orderByDesc('activity_date')->get()->map(fn ($a) => $this->present($a))->values();
    }

    public function show(Request $request, string $activityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $activity = $this->prospectActivityOrFail($user->company_id, $activityId);

        if (! $this->canViewActivity($user, $activity)) {
            throw new ApiException(403, 'Unauthorized');
        }

        return response()->json($this->present($activity));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'required|uuid',
            'activity_type' => 'required|in:call,email,meeting,note,follow_up,proposal,demo,negotiation',
            'subject' => 'required|string|min:1|max:255',
            'description' => 'sometimes|nullable|string',
            'activity_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'status' => 'sometimes|in:planned,completed,pending,cancelled',
        ]);

        $activity = ProspectActivity::create([
            'company_id' => $user->company_id,
            'customer_id' => $data['customer_id'],
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
                'activity_type' => $activity->activity_type,
                'subject' => $activity->subject,
                'customer_id' => $activity->customer_id,
            ],
        );

        return response()->json($this->present($activity->fresh()));
    }

    public function update(Request $request, string $activityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $activity = $this->prospectActivityOrFail($user->company_id, $activityId);

        if (! $this->canViewActivity($user, $activity)) {
            throw new ApiException(403, 'Unauthorized');
        }

        $data = $request->validate([
            'activity_type' => 'sometimes|in:call,email,meeting,note,follow_up,proposal,demo,negotiation',
            'subject' => 'sometimes|string|min:1|max:255',
            'description' => 'sometimes|nullable|string',
            'activity_date' => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            'status' => 'sometimes|in:planned,completed,pending,cancelled',
        ]);

        $changes = [];
        foreach (['activity_type', 'subject', 'description', 'activity_date', 'status'] as $field) {
            if (isset($data[$field]) && $data[$field] !== $activity->{$field}) {
                $changes[$field] = [$activity->{$field}, $data[$field]];
            }
        }

        if (! empty($changes)) {
            $oldData = [];
            $newData = [];
            foreach ($changes as $field => [$oldVal, $newVal]) {
                $oldData[$field] = $oldVal;
                $newData[$field] = $newVal;
            }

            $activity->update(array_merge($data, ['last_edited_by_user_id' => $user->id]));

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

    public function destroy(Request $request, string $activityId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $activity = $this->prospectActivityOrFail($user->company_id, $activityId);

        if (! $this->canViewActivity($user, $activity)) {
            throw new ApiException(403, 'Unauthorized');
        }

        Audit::record(
            entityType: 'prospect_activity',
            entityId: $activity->id,
            action: 'deleted',
            actorUserId: $user->id,
            companyId: $user->company_id,
            oldValue: [
                'subject' => $activity->subject,
                'customer_id' => $activity->customer_id,
                'activity_type' => $activity->activity_type,
            ],
        );

        $activity->delete();

        return response()->noContent();
    }
}
