<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Contract;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\ServiceRecordAttachment;
use App\Models\ServiceRecordSignoff;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\MobileFileStorage;
use App\Services\Numbering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile Web App API. Mirrors backend/app/routers/mobile.py 1:1
 * (planned-work.md #1).
 *
 * Confirmed 2026-09-12: same login credentials as the desktop app but
 * filtered to the engineer's OWN assigned Job Orders; time in/out
 * replaces manually keyed minutes; the chop photo is camera-captured
 * and watermarked so it cannot be reused; a live connection is assumed
 * (no offline mode); sign-off is a finger-drawn signature plus a typed
 * name; photos and videos with no count or size limit.
 *
 * Gated on `service_records` -- the mobile app is a different front
 * door to the same module, not a module of its own.
 *
 * THE OWNERSHIP RULE runs through every endpoint: a Job Order not
 * assigned to the caller is 403, and a Service Record belonging to
 * another engineer is 403. Company scoping is 404, as everywhere else.
 * The two are deliberately different: "not yours" is a different fact
 * from "does not exist".
 */
class MobileController extends Controller
{
    private const MODULE = 'service_records';

    /** 20MB, matching DocumentService's confirmed per-file cap. */
    private const MAX_UPLOAD = 20 * 1024 * 1024;

    // ── Job Orders ──────────────────────────────────────────────────

    public function myJobOrders(Request $request)
    {
        $user = $this->viewer($request);

        $orders = JobOrder::where('company_id', $user->company_id)
            ->where('assigned_to_user_id', $user->id)
            ->whereIn('status', [JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED])
            ->orderByDesc('created_at')->get();

        return response()->json($orders->map(fn (JobOrder $jo) => [
            'id' => $jo->id,
            'job_order_number' => $jo->job_order_number,
            'subject' => $jo->subject,
            'status' => $jo->status,
            'priority' => $jo->priority,
            'is_urgent' => $jo->is_urgent,
            'customer_name' => $jo->customer?->name ?? '',
            'contract_number' => $jo->contract?->contract_number,
            'due_date' => $jo->due_date?->toDateString(),
            'created_at' => $jo->created_at?->toIso8601String(),
        ]));
    }

    public function jobOrderDetail(Request $request, string $jobOrderId)
    {
        $user = $this->viewer($request);
        $jo = $this->myJobOrderOrFail($user, $jobOrderId);

        $records = ServiceRecord::where('job_order_id', $jo->id)->orderByDesc('work_date')->get();
        $signedIds = ServiceRecordSignoff::whereIn('service_record_id', $records->pluck('id'))
            ->pluck('service_record_id')->all();
        $counts = ServiceRecordAttachment::whereIn('service_record_id', $records->pluck('id'))
            ->where('is_deleted', false)
            ->selectRaw('service_record_id, COUNT(*) AS c')
            ->groupBy('service_record_id')->pluck('c', 'service_record_id');
        $names = User::whereIn('id', $records->pluck('employee_user_id')->filter()->unique())
            ->pluck('full_name', 'id');

        $contract = $jo->contract_id ? Contract::find($jo->contract_id) : null;

        return response()->json([
            'id' => $jo->id,
            'job_order_number' => $jo->job_order_number,
            'subject' => $jo->subject,
            'status' => $jo->status,
            'priority' => $jo->priority,
            'is_urgent' => $jo->is_urgent,
            'customer_name' => $jo->customer?->name ?? '',
            'contract_number' => $contract?->contract_number,
            'contract_remaining_minutes' => $contract?->remaining_minutes,
            'due_date' => $jo->due_date?->toDateString(),
            'service_records' => $records->map(fn (ServiceRecord $r) => [
                'id' => $r->id,
                'service_record_number' => $r->service_record_number,
                'work_date' => $r->work_date?->toDateString(),
                'raw_minutes' => $r->raw_minutes,
                'rounded_minutes' => $r->rounded_minutes,
                'status' => $r->status,
                'outcome' => $r->outcome,
                'completion_status' => $r->completion_status,
                'is_after_hours' => $r->is_after_hours,
                'work_description' => $r->work_description,
                'time_in' => $r->time_in?->toIso8601String(),
                'time_out' => $r->time_out?->toIso8601String(),
                'employee_name' => $names[$r->employee_user_id] ?? '',
                'has_signoff' => in_array($r->id, $signedIds, true),
                'attachment_count' => (int) ($counts[$r->id] ?? 0),
            ])->values(),
        ]);
    }

    // ── Time In / Time Out ──────────────────────────────────────────

    public function timeIn(Request $request, string $jobOrderId)
    {
        $user = $this->editor($request);
        $jo = $this->myJobOrderOrFail($user, $jobOrderId);
        if (in_array($jo->status, [JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID], true)) {
            throw new ApiException(422, 'This Job Order is closed or voided');
        }

        // One open time-in at a time, per engineer per Job Order.
        $open = ServiceRecord::where('job_order_id', $jo->id)
            ->where('employee_user_id', $user->id)
            ->whereNotNull('time_in')->whereNull('time_out')->first();
        if ($open) {
            throw new ApiException(422, "You already have an open time-in ({$open->service_record_number}). Tap Time Out first.");
        }

        $record = DB::transaction(function () use ($jo, $user) {
            $now = Carbon::now();
            $record = ServiceRecord::create([
                'company_id' => $user->company_id,
                'job_order_id' => $jo->id,
                'employee_user_id' => $user->id,
                'service_record_number' => Numbering::next($user->company_id, 'service_record'),
                'work_date' => $now->toDateString(),
                'raw_minutes' => 0,
                'rounded_minutes' => 0,
                'time_in' => $now,
                'status' => ServiceRecord::STATUS_SUBMITTED,
                'completion_status' => ServiceRecord::UNCOMPLETED,
            ]);

            // Starting work on an OPEN Job Order claims it.
            if ($jo->status === JobOrder::STATUS_OPEN) {
                $jo->status = JobOrder::STATUS_ASSIGNED;
                $jo->assigned_to_user_id = $user->id;
                $jo->save();
            }

            Audit::record(
                entityType: 'service_record',
                entityId: $record->id,
                action: 'time_in',
                actorUserId: $user->id,
                details: "{$record->service_record_number} time-in at {$now->toIso8601String()}",
            );

            return $record;
        });

        // Re-read, as timeOut() does: the in-memory time_in was written
        // without an offset and would be re-parsed in the app timezone.
        $record->refresh();

        return response()->json([
            'id' => $record->id,
            'service_record_number' => $record->service_record_number,
            'time_in' => $record->time_in->toIso8601String(),
        ]);
    }

    public function timeOut(Request $request, string $recordId)
    {
        $user = $this->editor($request);
        $data = $request->validate([
            'completion_status' => 'sometimes|string',
            'is_after_hours' => 'sometimes|boolean',
            'work_description' => 'sometimes|nullable|string',
        ]);

        $record = $this->myServiceRecordOrFail($user, $recordId);
        if ($record->time_in === null) {
            throw new ApiException(422, 'No time-in recorded for this Service Record');
        }
        if ($record->time_out !== null) {
            throw new ApiException(422, 'Time Out already recorded');
        }

        DB::transaction(function () use ($record, $user, $data) {
            $now = Carbon::now();
            // Always at least a minute, and always rounded UP -- the
            // same ceil/round-up pair Python uses, so a 20-second call
            // still bills the contract's minimum increment.
            $elapsed = $now->getTimestamp() - $record->time_in->getTimestamp();
            $rawMinutes = max(1, (int) ceil($elapsed / 60));

            $record->time_out = $now;
            $record->raw_minutes = $rawMinutes;
            $record->rounded_minutes = ServiceRecord::roundUpToNearest($rawMinutes);
            $record->is_after_hours = $data['is_after_hours'] ?? false;
            $description = trim((string) ($data['work_description'] ?? ''));
            $record->work_description = $description !== '' ? $description : null;
            $record->completion_status = ($data['completion_status'] ?? 'U') === 'C'
                ? ServiceRecord::COMPLETED
                : ServiceRecord::UNCOMPLETED;
            $record->save();

            Audit::record(
                entityType: 'service_record',
                entityId: $record->id,
                action: 'time_out',
                actorUserId: $user->id,
                details: "{$record->service_record_number} time-out at {$now->toIso8601String()}, "
                    ."{$record->raw_minutes}min raw, {$record->rounded_minutes}min rounded",
            );
        });

        $record->refresh();

        return response()->json([
            'id' => $record->id,
            'service_record_number' => $record->service_record_number,
            'time_in' => $record->time_in->toIso8601String(),
            'time_out' => $record->time_out->toIso8601String(),
            'raw_minutes' => $record->raw_minutes,
            'rounded_minutes' => $record->rounded_minutes,
        ]);
    }

    public function myOpenTimeIn(Request $request)
    {
        $user = $this->viewer($request);

        $open = ServiceRecord::where('company_id', $user->company_id)
            ->where('employee_user_id', $user->id)
            ->whereNotNull('time_in')->whereNull('time_out')->first();

        // Python returns a bare null when there is nothing open, and the
        // frontend is written against that: MobileApp.tsx does
        // `{openTimeIn && ...}` and then reads .time_in off it.
        // response()->json(null) does NOT produce it -- Symfony's
        // JsonResponse constructor does `$data ??= new \ArrayObject()`,
        // so null is encoded as `{}`, which is truthy in JS and blanked
        // the whole Mobile screen with a TypeError for every engineer
        // with no open time-in. fromJsonString writes the literal null.
        if (! $open) {
            return JsonResponse::fromJsonString('null');
        }

        $jo = JobOrder::find($open->job_order_id);

        return response()->json([
            'service_record_id' => $open->id,
            'service_record_number' => $open->service_record_number,
            'job_order_id' => $open->job_order_id,
            'job_order_number' => $jo?->job_order_number ?? '',
            'job_order_subject' => $jo?->subject ?? '',
            'time_in' => $open->time_in->toIso8601String(),
        ]);
    }

    // ── Attachments ─────────────────────────────────────────────────

    public function uploadAttachment(Request $request, string $recordId)
    {
        $user = $this->editor($request);
        $record = $this->serviceRecordOrFail($user, $recordId);

        $file = $request->file('file');
        if ($file === null) {
            throw new ApiException(422, 'No file was uploaded');
        }
        $contentType = $file->getClientMimeType() ?: 'application/octet-stream';
        if (str_starts_with($contentType, 'image/')) {
            $kind = ServiceRecordAttachment::KIND_WORK_PHOTO;
        } elseif (str_starts_with($contentType, 'video/')) {
            $kind = ServiceRecordAttachment::KIND_WORK_VIDEO;
        } else {
            throw new ApiException(422, 'Only photos and videos are accepted');
        }

        $data = (string) file_get_contents($file->getRealPath());
        if (strlen($data) > self::MAX_UPLOAD) {
            throw new ApiException(422, sprintf('File exceeds 20MB limit (%s bytes).', number_format(strlen($data))));
        }
        $originalName = $file->getClientOriginalName() ?: 'upload';

        $attachment = DB::transaction(function () use ($record, $user, $kind, $data, $originalName, $contentType) {
            $attachmentId = (string) Str::uuid();
            $stored = MobileFileStorage::saveFile(
                $user->company_id, $record->id, $attachmentId, $originalName, $data
            );

            // The stored filename is named after the attachment id, so
            // the row must carry the SAME id -- `id` is not
            // mass-assignable, so it is set explicitly rather than
            // passed to create(), which would silently drop it and
            // leave the file and the row disagreeing.
            $attachment = new ServiceRecordAttachment([
                'company_id' => $user->company_id,
                'service_record_id' => $record->id,
                'uploaded_by_user_id' => $user->id,
                'kind' => $kind,
                'original_filename' => $originalName,
                'stored_filename' => $stored,
                'content_type' => $contentType,
                'file_size_bytes' => strlen($data),
            ]);
            $attachment->id = $attachmentId;
            $attachment->save();

            Audit::record(
                entityType: 'service_record_attachment',
                entityId: $attachment->id,
                action: 'uploaded',
                actorUserId: $user->id,
                details: "{$originalName} (".strlen($data)." bytes) on {$record->service_record_number}",
            );

            return $attachment;
        });

        return response()->json([
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'original_filename' => $attachment->original_filename,
            'content_type' => $attachment->content_type,
            'file_size_bytes' => $attachment->file_size_bytes,
        ]);
    }

    public function listAttachments(Request $request, string $recordId)
    {
        $user = $this->viewer($request);
        $record = $this->serviceRecordOrFail($user, $recordId);

        return response()->json(
            ServiceRecordAttachment::where('service_record_id', $record->id)
                ->where('is_deleted', false)
                ->orderBy('uploaded_at')->get()
                ->map(fn (ServiceRecordAttachment $a) => [
                    'id' => $a->id,
                    'kind' => $a->kind,
                    'original_filename' => $a->original_filename,
                    'content_type' => $a->content_type,
                    'file_size_bytes' => $a->file_size_bytes,
                    'uploaded_at' => $a->uploaded_at?->toIso8601String(),
                ])
        );
    }

    public function downloadAttachment(Request $request, string $attachmentId)
    {
        $user = $this->viewer($request);
        $att = ServiceRecordAttachment::where('company_id', $user->company_id)
            ->where('is_deleted', false)->find($attachmentId);
        if (! $att) {
            throw new ApiException(404, 'Attachment not found');
        }

        $path = MobileFileStorage::filePath($att->company_id, $att->service_record_id, $att->stored_filename);
        if ($path === null) {
            throw new ApiException(404, 'File not found on disk');
        }

        return response()->download($path, $att->original_filename, ['Content-Type' => $att->content_type]);
    }

    public function deleteAttachment(Request $request, string $attachmentId)
    {
        $user = $this->editor($request);
        $att = ServiceRecordAttachment::where('company_id', $user->company_id)->find($attachmentId);
        if (! $att) {
            throw new ApiException(404, 'Attachment not found');
        }

        DB::transaction(function () use ($att, $user) {
            // Soft delete only: the row is flagged and the file stays on
            // disk, so a deleted work photo is still recoverable.
            $att->is_deleted = true;
            $att->save();

            Audit::record(
                entityType: 'service_record_attachment',
                entityId: $att->id,
                action: 'soft_deleted',
                actorUserId: $user->id,
                details: "Deleted {$att->original_filename} from SR {$att->service_record_id}",
            );
        });

        return response()->json(['deleted' => true]);
    }

    // ── Sign-off ────────────────────────────────────────────────────

    public function createSignoff(Request $request, string $recordId)
    {
        $user = $this->editor($request);
        $data = $request->validate([
            'signer_name' => 'required|string|max:255',
            'signature_data_uri' => 'required|string',
        ]);
        $record = $this->serviceRecordOrFail($user, $recordId);

        if (ServiceRecordSignoff::where('service_record_id', $record->id)->exists()) {
            throw new ApiException(422, 'This Service Record already has a sign-off');
        }

        $chop = $request->file('chop_photo');
        if ($chop === null) {
            throw new ApiException(422, 'Chop photo is empty');
        }
        $chopData = (string) file_get_contents($chop->getRealPath());
        if ($chopData === '') {
            throw new ApiException(422, 'Chop photo is empty');
        }

        $signoff = DB::transaction(function () use ($record, $user, $data, $chopData) {
            $now = Carbon::now();

            // Watermarked with the SR number and timestamp, which is what
            // makes the confirmed no-reuse rule enforceable rather than
            // a request: the photo is tied to this one record.
            $watermarked = MobileFileStorage::watermarkChopPhoto(
                $chopData, $record->service_record_number, $now
            );

            $chopId = (string) Str::uuid();
            $stored = MobileFileStorage::saveFile(
                $user->company_id, $record->id, $chopId, 'chop-photo.jpg', $watermarked
            );

            $chopAttachment = new ServiceRecordAttachment([
                'company_id' => $user->company_id,
                'service_record_id' => $record->id,
                'uploaded_by_user_id' => $user->id,
                'kind' => ServiceRecordAttachment::KIND_CHOP_PHOTO,
                'original_filename' => 'chop-photo.jpg',
                'stored_filename' => $stored,
                'content_type' => 'image/jpeg',
                'file_size_bytes' => strlen($watermarked),
            ]);
            $chopAttachment->id = $chopId;
            $chopAttachment->save();

            $signoff = ServiceRecordSignoff::create([
                'company_id' => $user->company_id,
                'service_record_id' => $record->id,
                'signer_name' => trim($data['signer_name']),
                'signature_data_uri' => $data['signature_data_uri'],
                'chop_attachment_id' => $chopId,
                'signed_by_user_id' => $user->id,
                'signed_at' => $now,
            ]);

            Audit::record(
                entityType: 'service_record_signoff',
                entityId: $signoff->id,
                action: 'signed_off',
                actorUserId: $user->id,
                details: "{$record->service_record_number} signed by ".trim($data['signer_name'])
                    ." at {$now->toIso8601String()}",
            );

            return $signoff;
        });

        $signoff->refresh();

        return response()->json([
            'id' => $signoff->id,
            'signer_name' => $signoff->signer_name,
            'chop_attachment_id' => $signoff->chop_attachment_id,
            'signed_at' => $signoff->signed_at->toIso8601String(),
        ]);
    }

    public function getSignoff(Request $request, string $recordId)
    {
        $user = $this->viewer($request);
        $record = $this->serviceRecordOrFail($user, $recordId);

        $signoff = ServiceRecordSignoff::where('service_record_id', $record->id)->first();
        if (! $signoff) {
            // Literal null, not `{}` -- see myOpenTimeIn above.
            return JsonResponse::fromJsonString('null');
        }

        return response()->json([
            'id' => $signoff->id,
            'signer_name' => $signoff->signer_name,
            'signature_data_uri' => $signoff->signature_data_uri,
            'chop_attachment_id' => $signoff->chop_attachment_id,
            'signed_at' => $signoff->signed_at?->toIso8601String(),
            'signed_by_user_id' => $signoff->signed_by_user_id,
        ]);
    }

    // ── Shared guards ───────────────────────────────────────────────

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return $user;
    }

    private function editor(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        return $user;
    }

    /** Another company's Job Order is 404; someone else's is 403. */
    private function myJobOrderOrFail(User $user, string $jobOrderId): JobOrder
    {
        $jo = JobOrder::where('company_id', $user->company_id)->find($jobOrderId);
        if (! $jo) {
            throw new ApiException(404, 'Job Order not found');
        }
        if ($jo->assigned_to_user_id !== $user->id) {
            throw new ApiException(403, 'This Job Order is not assigned to you');
        }

        return $jo;
    }

    /** Company-scoped only -- used where Python does not check ownership. */
    private function serviceRecordOrFail(User $user, string $recordId): ServiceRecord
    {
        $record = ServiceRecord::where('company_id', $user->company_id)->find($recordId);
        if (! $record) {
            throw new ApiException(404, 'Service Record not found');
        }

        return $record;
    }

    /** Time-out additionally requires the record to be the caller's own. */
    private function myServiceRecordOrFail(User $user, string $recordId): ServiceRecord
    {
        $record = $this->serviceRecordOrFail($user, $recordId);
        if ($record->employee_user_id !== $user->id) {
            throw new ApiException(403, 'This Service Record belongs to another staff member');
        }

        return $record;
    }
}
