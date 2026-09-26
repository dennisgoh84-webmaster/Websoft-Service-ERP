<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;

/**
 * What an eApproval decision does to the document behind it (Backlog 2,
 * 2026-09-26). Called once a request is approved or rejected in the
 * Approval Center:
 *
 * - Purchase Order (above the supplier's limit): approved once every
 *   request for it is approved; cancelled, with the approver's reason,
 *   as soon as one is rejected.
 * - Payment Voucher: nothing changes on the voucher itself -- the Bank
 *   step reads where its approval stands (ApprovalService::stateOf).
 * - Service Record: decided on its own approval screen, where the hours
 *   to deduct are keyed in (see ServiceRecordService), never here.
 */
class ApprovalOutcomes
{
    public static function resolved(ApprovalRequest $request, string $userId, ?string $comment): void
    {
        if ($request->entity_type !== 'purchase_order') {
            return;
        }
        $po = PurchaseOrder::where('company_id', $request->company_id)->find($request->entity_id);
        if (! $po || $po->status !== PurchaseOrder::STATUS_PENDING_APPROVAL) {
            return;
        }
        $state = ApprovalService::stateOf($request->company_id, 'purchase_order', $po->id);
        if ($state === ApprovalRequest::STATUS_APPROVED) {
            $po->status = PurchaseOrder::STATUS_APPROVED;
            $po->approved_by_user_id = $userId;
            $po->approved_at = Carbon::now();
            $po->save();
            Audit::record('purchase_order', $po->id, 'approved', $userId,
                details: "{$po->po_number} approved through eApproval", newValue: ['status' => $po->status]);
        } elseif ($state === ApprovalRequest::STATUS_REJECTED) {
            $po->status = PurchaseOrder::STATUS_CANCELLED;
            $po->cancel_reason = 'Rejected in eApproval'.($comment ? ": {$comment}" : '');
            $po->cancelled_at = Carbon::now();
            $po->cancelled_by_user_id = $userId;
            $po->save();
            Audit::record('purchase_order', $po->id, 'cancelled', $userId, reason: $po->cancel_reason,
                details: "{$po->po_number} rejected in eApproval", newValue: ['status' => $po->status]);
        }
    }
}
