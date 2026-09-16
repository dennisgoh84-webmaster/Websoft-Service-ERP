<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\User;
use Illuminate\Http\Request;

class MobileJobOrderController extends Controller
{
    // Stub for mobile job order endpoints
    // These delegate to existing JobOrderController endpoints
    // with mobile-specific formatting

    public function index(Request $request)
    {
        // Delegate to JobOrderController
        $controller = new JobOrderController();
        return $controller->index($request);
    }

    public function show(Request $request, string $jobOrderId)
    {
        // Delegate to JobOrderController
        $controller = new JobOrderController();
        return $controller->show($request, $jobOrderId);
    }

    public function timeIn(Request $request, string $jobOrderId)
    {
        // Delegate to ServiceRecordController or existing logic
        $user = Authenticate::user($request);
        // TODO: Implement time-in logic for mobile
        return response()->json(['status' => 'ok']);
    }

    public function timeOut(Request $request, string $recordId)
    {
        // Delegate to ServiceRecordController
        $user = Authenticate::user($request);
        // TODO: Implement time-out logic for mobile
        return response()->json(['status' => 'ok']);
    }

    public function listAttachments(Request $request, string $recordId)
    {
        // TODO: List attachments for a service record
        return response()->json([]);
    }

    public function uploadAttachment(Request $request, string $recordId)
    {
        // TODO: Upload attachment to a service record
        return response()->json(['status' => 'ok']);
    }

    public function getSignoff(Request $request, string $recordId)
    {
        // TODO: Get sign-off info for a service record
        return response()->json(null);
    }

    public function saveSignoff(Request $request, string $recordId)
    {
        // TODO: Save sign-off (signature + chop photo)
        return response()->json(['status' => 'ok']);
    }

    public function getOpenTimeIn(Request $request)
    {
        $user = Authenticate::user($request);
        // TODO: Get the currently open time-in for this user
        return response()->json(null);
    }

    public function downloadAttachment(Request $request, string $attachmentId)
    {
        // TODO: Download attachment file
        return response()->download('/path/to/file');
    }

    public function deleteAttachment(Request $request, string $attachmentId)
    {
        // TODO: Delete attachment (soft-delete)
        return response()->noContent();
    }
}
