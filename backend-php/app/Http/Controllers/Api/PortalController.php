<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePortal;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PortalUser;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Http\Request;

/**
 * Customer Helpdesk Portal -- customer-facing data endpoints
 * (PORTAL-002/005/006). Mirrors the data half of
 * backend/app/routers/portal.py; the auth half of that same Python
 * router is in App\Http\Controllers\Api\PortalAuthController. Design:
 * docs/customer-portal-design.md §6.
 *
 * EVERY query below filters on the portal user's own
 * `contact.customer_id` -- never on an id taken from the request -- so
 * a portal user can only ever see their own customer's data. Where a
 * path does carry an id (job order detail), a mismatch 404s rather
 * than 403s: confirming that a document id exists for a *different*
 * customer is exactly the information leak design §9.4 tests against.
 *
 * These responses are deliberately thin views over the same records
 * staff see, per design §6's "deliberately not exposed" list: no
 * quotation/rate internals, no GP/cost figures on an Invoice
 * (`cost_sgd`/`gp_sgd` are staff-only), no internal notes, no raw or
 * deducted minutes (customers see the rounded, approved SRV-007
 * figure only), no other contacts, and no staff names beyond the
 * engineer assigned to the work.
 */
class PortalController extends Controller
{
    private function portalUser(Request $request): PortalUser
    {
        return AuthenticatePortal::portalUser($request);
    }

    private function customerId(Request $request): string
    {
        return $this->portalUser($request)->contact->customer_id;
    }

    public function contracts(Request $request)
    {
        $contracts = Contract::where('customer_id', $this->customerId($request))
            ->orderByDesc('end_date')
            ->get();

        return response()->json($contracts->map(fn (Contract $c) => [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'contract_kind' => $c->contract_kind,
            'status' => $c->status,
            'contracted_hours' => $c->contracted_minutes / 60,
            'consumed_hours' => $c->consumed_minutes / 60,
            'remaining_hours' => $c->remainingMinutes() / 60,
            'start_date' => optional($c->start_date)->toDateString(),
            'end_date' => optional($c->end_date)->toDateString(),
        ])->values());
    }

    public function jobOrders(Request $request)
    {
        $query = JobOrder::where('customer_id', $this->customerId($request));
        if ($request->filled('contract_id')) {
            // PORTAL-006: scope job orders (and via them, service
            // records) to one contract -- still customer-filtered above
            // first, so a contract_id belonging to another customer
            // just returns empty, not another customer's job orders.
            $query->where('contract_id', $request->query('contract_id'));
        }
        $jobOrders = $query->orderByDesc('created_at')->get();
        $numbers = $this->contractNumberMap($jobOrders->pluck('contract_id')->filter()->unique()->all());

        return response()->json($jobOrders->map(fn (JobOrder $jo) => $this->jobOrderOut($jo, $numbers))->values());
    }

    public function jobOrderDetail(Request $request, string $jobOrderId)
    {
        $jobOrder = JobOrder::find($jobOrderId);
        // A different customer's job order id is "not found", not
        // "forbidden" -- never confirm that the id exists at all
        // (design §9.4).
        if (! $jobOrder || $jobOrder->customer_id !== $this->customerId($request)) {
            throw new ApiException(404, 'Job order not found');
        }

        $records = ServiceRecord::where('job_order_id', $jobOrder->id)
            ->orderByDesc('work_date')
            ->get();
        $numbers = $this->contractNumberMap($jobOrder->contract_id ? [$jobOrder->contract_id] : []);
        $base = $this->jobOrderOut($jobOrder, $numbers);

        return response()->json(array_merge($base, [
            'service_records' => $records->map(fn (ServiceRecord $r) => [
                'id' => $r->id,
                'service_record_number' => $r->service_record_number,
                'job_order_id' => $jobOrder->id,
                'job_order_number' => $jobOrder->job_order_number,
                'work_date' => optional($r->work_date)->toDateString(),
                'engineer_name' => $this->engineerName($r->employee_user_id) ?? '',
                'minutes' => $r->rounded_minutes,
                'completion_status' => $r->completion_status,
                'status' => $r->status,
                'contract_id' => $base['contract_id'],
                'contract_number' => $base['contract_number'],
            ])->values(),
        ]));
    }

    public function serviceRecords(Request $request)
    {
        $query = ServiceRecord::query()
            ->join('job_orders', 'service_records.job_order_id', '=', 'job_orders.id')
            ->where('job_orders.customer_id', $this->customerId($request));
        if ($request->filled('contract_id')) {
            $query->where('job_orders.contract_id', $request->query('contract_id')); // PORTAL-006
        }
        $rows = $query
            ->orderByDesc('service_records.work_date')
            ->select([
                'service_records.*',
                'job_orders.job_order_number as jo_number',
                'job_orders.contract_id as jo_contract_id',
            ])
            ->get();
        $numbers = $this->contractNumberMap($rows->pluck('jo_contract_id')->filter()->unique()->all());

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id,
            'service_record_number' => $r->service_record_number,
            'job_order_id' => $r->job_order_id,
            'job_order_number' => $r->jo_number,
            'work_date' => optional($r->work_date)->toDateString(),
            'engineer_name' => $this->engineerName($r->employee_user_id) ?? '',
            'minutes' => $r->rounded_minutes,
            'completion_status' => $r->completion_status,
            'status' => $r->status,
            'contract_id' => $r->jo_contract_id,
            'contract_number' => $r->jo_contract_id ? ($numbers[$r->jo_contract_id] ?? null) : null,
        ])->values());
    }

    /**
     * PORTAL-005: a customer's own Invoices, same figures as their PDF
     * copy -- net, GST, total, amount paid, outstanding, status. Never
     * the GP/cost fields on Invoice (those are staff-only).
     */
    public function invoices(Request $request)
    {
        $invoices = Invoice::where('customer_id', $this->customerId($request))
            ->orderByDesc('issued_at')
            ->get();
        $numbers = $this->contractNumberMap($invoices->pluck('contract_id')->filter()->unique()->all());

        return response()->json($invoices->map(fn (Invoice $inv) => [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'invoice_type' => $inv->invoice_type,
            'description' => $inv->description,
            'contract_id' => $inv->contract_id,
            'contract_number' => $inv->contract_id ? ($numbers[$inv->contract_id] ?? null) : null,
            // Money: computed exactly on the model, cast to float only
            // here at the JSON boundary (docs/php-conversion-plan.md's
            // Decimal/money handling convention).
            'amount_sgd' => (float) $inv->amount_sgd,
            'gst_amount_sgd' => (float) $inv->gst_amount_sgd,
            'total_amount_sgd' => (float) $inv->total_amount_sgd,
            'amount_paid_sgd' => (float) $inv->amount_paid_sgd,
            'outstanding_sgd' => $inv->outstandingSgd()->toFloat(),
            'status' => $inv->status,
            'due_date' => optional($inv->due_date)->toDateString(),
            'issued_at' => optional($inv->issued_at)->toIso8601String(),
        ])->values());
    }

    /**
     * PORTAL-005: a customer's own Payments (receipts) and which of
     * their own invoices each one was allocated against.
     */
    public function payments(Request $request)
    {
        $payments = Payment::with('allocations')
            ->where('customer_id', $this->customerId($request))
            ->orderByDesc('payment_date')
            ->get();

        $invoiceIds = $payments->flatMap(fn (Payment $p) => $p->allocations->pluck('invoice_id'))->unique()->all();
        $invoiceNumbers = $invoiceIds
            ? Invoice::whereIn('id', $invoiceIds)->pluck('invoice_number', 'id')->all()
            : [];

        return response()->json($payments->map(fn (Payment $p) => [
            'id' => $p->id,
            'voucher_number' => $p->voucher_number,
            'payment_date' => optional($p->payment_date)->toDateString(),
            'amount_sgd' => (float) $p->amount_sgd,
            'method' => $p->method,
            'reference' => $p->reference,
            'allocations' => $p->allocations->map(fn ($a) => [
                'invoice_id' => $a->invoice_id,
                'invoice_number' => $invoiceNumbers[$a->invoice_id] ?? null,
                'amount_sgd' => (float) $a->amount_sgd,
            ])->values(),
        ])->values());
    }

    public function incidents(Request $request)
    {
        $incidents = Incident::where('customer_id', $this->customerId($request))
            ->orderByDesc('created_at')
            ->get();

        $jobOrderIds = $incidents->pluck('converted_job_order_id')->filter()->unique()->all();
        $jobOrderNumbers = $jobOrderIds
            ? JobOrder::whereIn('id', $jobOrderIds)->pluck('job_order_number', 'id')->all()
            : [];

        return response()->json($incidents->map(fn (Incident $i) => $this->incidentOut($i, $jobOrderNumbers))->values());
    }

    public function createIncident(Request $request)
    {
        $portalUser = $this->portalUser($request);
        $data = $request->validate([
            'subject' => 'required|string|min:1|max:255',
            'description' => 'sometimes|nullable|string',
        ]);

        $contact = $portalUser->contact;
        $incident = IncidentService::createIncident(
            companyId: $portalUser->company_id,
            customerId: $contact->customer_id,
            source: Incident::SOURCE_PORTAL,
            subject: $data['subject'],
            description: $data['description'] ?? null,
            senderName: $contact->name,
            senderEmail: $portalUser->email,
            senderPhone: $contact->phone,
            createdByUserId: null,
            raisedByPortalUserId: $portalUser->id,
            portalActorName: "{$contact->name} (portal)",
        );

        return response()->json($this->incidentOut($incident->fresh(), []));
    }

    // ---- presenters ------------------------------------------------------

    /** @return array<string, mixed> */
    private function incidentOut(Incident $incident, array $jobOrderNumbers): array
    {
        return [
            'id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'subject' => $incident->subject,
            'description' => $incident->description,
            'status' => $incident->status,
            'created_at' => optional($incident->created_at)->toIso8601String(),
            // A friendlier status than the bare enum once routed --
            // which Job Order to follow for progress. None until/unless
            // status becomes converted AND it was routed to a Job Order
            // specifically (a Quotation/Software Task carries no
            // money-free customer-facing detail worth exposing here).
            'converted_job_order_number' => $incident->converted_job_order_id
                ? ($jobOrderNumbers[$incident->converted_job_order_id] ?? null)
                : null,
        ];
    }

    /**
     * @param  array<string, string>  $contractNumbers
     * @return array<string, mixed>
     */
    private function jobOrderOut(JobOrder $jobOrder, array $contractNumbers): array
    {
        return [
            'id' => $jobOrder->id,
            'job_order_number' => $jobOrder->job_order_number,
            'subject' => $jobOrder->subject,
            'job_order_type' => $jobOrder->job_order_type,
            'status' => $jobOrder->status,
            'assigned_engineer_name' => $this->engineerName($jobOrder->assigned_to_user_id),
            'due_date' => optional($jobOrder->due_date)->toDateString(),
            'contract_id' => $jobOrder->contract_id,
            'contract_number' => $jobOrder->contract_id ? ($contractNumbers[$jobOrder->contract_id] ?? null) : null,
        ];
    }

    private function engineerName(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return User::find($userId)?->full_name;
    }

    /**
     * Batches the Contract lookup for a list of job orders/invoices in
     * one query instead of one per row.
     *
     * @param  array<int, string>  $contractIds
     * @return array<string, string>
     */
    private function contractNumberMap(array $contractIds): array
    {
        if (! $contractIds) {
            return [];
        }

        return Contract::whereIn('id', $contractIds)->pluck('contract_number', 'id')->all();
    }
}
