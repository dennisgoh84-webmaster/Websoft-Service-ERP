<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\ProspectActivity;
use App\Models\User;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * The Mobile App's CRM tab list. Opening, editing and deleting an
 * activity go through the desktop CrmController.
 */
class MobileCrmController extends Controller
{
    private const MODULE = 'crm';

    private function presentMobile(ProspectActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'customer_id' => $activity->customer_id,
            'customer_name' => $activity->customer?->name,
            'activity_type' => $activity->activity_type,
            'subject' => $activity->subject,
            'description' => $activity->description,
            'activity_date' => optional($activity->activity_date)->toJSON(),
            'status' => $activity->status,
            'created_by_name' => $activity->createdBy?->full_name,
            'created_at' => optional($activity->created_at)->toJSON(),
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

        // Sales Staff see only their own activities; the owner and Sales Manager see all.
        if ($user->role !== User::ROLE_OWNER && $user->role !== User::ROLE_SALES_MANAGER) {
            $query->where('created_by_user_id', $user->id);
        }

        return $query->orderByDesc('activity_date')
            ->limit(100)
            ->get()
            ->map(fn ($a) => $this->presentMobile($a))
            ->values();
    }
}
