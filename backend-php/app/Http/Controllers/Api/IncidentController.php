<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Exceptions\IncidentRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\IncidentService;
use Illuminate\Http\Request;

/**
 * Incident Module -- the Helpdesk front door for an incoming call or
 * email, before it's routed to a Quotation, Job Order, or Software
 * Task. Mirrors backend/app/routers/incidents.py -- see
 * App\Models\Incident and App\Services\IncidentService for the
 * confirmed rules (docs/open-business-decisions.md #36).
 *
 * Lives under "service_operations" (already labelled "Helpdesk /
 * Service Operations (Job Orders)" in the module catalog) rather than
 * a new module key, same as the Python router.
 *
 * KNOWN GAP (documented, not silently skipped -- see
 * docs/php-conversion-plan.md's Incidents entry): no
 * convert-to-software-task action/route, because
 * backend/app/models/software_tasks.py has no backend-php equivalent
 * yet, so there is no SoftwareTask model to create against.
 *
 * Portal-raised Incidents (source=portal, raised_by_portal_user_id)
 * were the module's other known gap and are now reachable: the
 * Customer Helpdesk Portal is converted and
 * App\Http\Controllers\Api\PortalController::createIncident() creates
 * them through the same App\Services\IncidentService::createIncident(),
 * so they land in this same staff queue and convert here like any
 * other. As in the Python router, `raised_by_portal_user_id` is not
 * part of this controller's own staff-facing response shape (the
 * Python IncidentOut schema does not carry it either).
 */
class IncidentController extends Controller
{
    private const MODULE = 'service_operations';

    private function incidentOrFail(string $companyId, string $incidentId): Incident
    {
        $incident = Incident::find($incidentId);
        if (! $incident || $incident->company_id !== $companyId) {
            throw new ApiException(404, 'Incident not found');
        }

        return $incident;
    }

    private function present(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'customer_id' => $incident->customer_id,
            'source' => $incident->source,
            'subject' => $incident->subject,
            'description' => $incident->description,
            'sender_name' => $incident->sender_name,
            'sender_email' => $incident->sender_email,
            'sender_phone' => $incident->sender_phone,
            'status' => $incident->status,
            'assigned_to_user_id' => $incident->assigned_to_user_id,
            'converted_quotation_id' => $incident->converted_quotation_id,
            'converted_job_order_id' => $incident->converted_job_order_id,
            'converted_software_task_id' => $incident->converted_software_task_id,
            'close_reason' => $incident->close_reason,
            'closed_at' => optional($incident->closed_at)->toJSON(),
            'created_at' => optional($incident->created_at)->toJSON(),
        ];
    }

    /**
     * Mirrors JobOrderOut's field set exactly (backend/app/schemas/
     * schemas.py) -- an incident-converted Job Order is always
     * SUPPORT-type with no milestones and no budget-overrun figure
     * (only a PROJECT-type Job Order has either), same as
     * JobOrderController::present() would compute for one.
     */
    private function presentJobOrder(JobOrder $jobOrder): array
    {
        return [
            'id' => $jobOrder->id,
            'job_order_number' => $jobOrder->job_order_number,
            'customer_id' => $jobOrder->customer_id,
            'contract_id' => $jobOrder->contract_id,
            'subject' => $jobOrder->subject,
            'job_order_type' => $jobOrder->job_order_type,
            'priority' => $jobOrder->priority,
            'status' => $jobOrder->status,
            'is_urgent' => $jobOrder->is_urgent,
            'assigned_to_user_id' => $jobOrder->assigned_to_user_id,
            'due_date' => optional($jobOrder->due_date)->toDateString(),
            'void_reason' => $jobOrder->void_reason,
            'budget_overrun_approved' => $jobOrder->budget_overrun_approved,
            'budget_overrun_approved_by' => $jobOrder->budget_overrun_approved_by,
            'budget_overrun_approved_at' => $jobOrder->budget_overrun_approved_at,
            'created_at' => optional($jobOrder->created_at)->toJSON(),
            'closed_at' => optional($jobOrder->closed_at)->toJSON(),
            'milestones' => [],
            'budget_overrun' => null,
        ];
    }

    /** Mirrors QuotationOut's field set exactly, same shape as QuotationController::present(). */
    private function presentQuotation(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'customer_id' => $quotation->customer_id,
            'quotation_date' => optional($quotation->quotation_date)->toDateString(),
            'valid_until' => optional($quotation->valid_until)->toDateString(),
            'status' => $quotation->status,
            'notes' => $quotation->notes,
            'amount_sgd' => (float) $quotation->amount_sgd,
            'tax_code' => $quotation->tax_code,
            'gst_rate' => (float) $quotation->gst_rate,
            'gst_amount_sgd' => (float) $quotation->gst_amount_sgd,
            'total_amount_sgd' => (float) $quotation->total_amount_sgd,
            'converted_contract_id' => $quotation->converted_contract_id,
            'converted_annual_contract_id' => $quotation->converted_annual_contract_id,
            'created_at' => optional($quotation->created_at)->toJSON(),
            'lines' => $quotation->lines->map(fn (QuotationLine $l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'unit_of_measure' => $l->unit_of_measure,
                'quantity' => (float) $l->quantity,
                'unit_price_sgd' => (float) $l->unit_price_sgd,
                'line_total_sgd' => (float) $l->line_total_sgd,
                'reference_code_id' => $l->reference_code_id,
                'cost_sgd' => $l->cost_sgd !== null ? (float) $l->cost_sgd : null,
            ])->values(),
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = Incident::where('company_id', $user->company_id);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        return $query->orderByDesc('created_at')->get()->map(fn ($i) => $this->present($i))->values();
    }

    public function show(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->incidentOrFail($user->company_id, $incident)));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'customer_id' => 'sometimes|nullable|uuid',
            'source' => 'sometimes|in:phone,email,other',
            'subject' => 'required|string|min:1|max:255',
            'description' => 'sometimes|nullable|string',
            'sender_name' => 'sometimes|nullable|string',
            'sender_email' => 'sometimes|nullable|string',
            'sender_phone' => 'sometimes|nullable|string',
        ]);

        $customerId = $data['customer_id'] ?? null;
        if ($customerId === null) {
            $customerId = IncidentService::tryMatchCustomerByEmail($user->company_id, $data['sender_email'] ?? null);
        }

        $incident = IncidentService::createIncident(
            companyId: $user->company_id,
            customerId: $customerId,
            source: $data['source'] ?? Incident::SOURCE_PHONE,
            subject: $data['subject'],
            description: $data['description'] ?? null,
            senderName: $data['sender_name'] ?? null,
            senderEmail: $data['sender_email'] ?? null,
            senderPhone: $data['sender_phone'] ?? null,
            createdByUserId: $user->id,
        );

        return response()->json($this->present($incident->fresh()));
    }

    // ---- Outlook Add-in endpoints -- see outlook-addin/README.md ----
    // These act directly on an email with no Incident yet in
    // existence, so they take the raw sender/subject/body rather than
    // an incident_id. Registered in routes/api/incidents.php before
    // any "/{incident}/..." route, same reasoning as the Python
    // router's own comment: a plain Laravel route parameter would
    // otherwise swallow "from-email" as a (garbage) incident id.

    /** The Outlook Add-in's "Convert to Incident" button. */
    public function storeFromEmail(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'sender_name' => 'sometimes|nullable|string',
            'sender_email' => 'required|string',
            'subject' => 'required|string|min:1|max:255',
            'body' => 'sometimes|nullable|string',
        ]);

        $customerId = IncidentService::tryMatchCustomerByEmail($user->company_id, $data['sender_email']);
        $incident = IncidentService::createIncident(
            companyId: $user->company_id,
            customerId: $customerId,
            source: Incident::SOURCE_EMAIL,
            subject: $data['subject'],
            description: $data['body'] ?? null,
            senderName: $data['sender_name'] ?? null,
            senderEmail: $data['sender_email'],
            senderPhone: null,
            createdByUserId: $user->id,
        );

        return response()->json($this->present($incident->fresh()));
    }

    /**
     * The Outlook Add-in's "Convert to Job Order" button. Confirmed
     * 2026-09-12: if the sender's email doesn't match an existing
     * Company/Individual, or that customer has no valid contract, this
     * falls back to creating a plain Incident instead of erroring --
     * exactly what "Convert to Incident" would have done.
     */
    public function storeJobOrderFromEmail(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'sender_name' => 'sometimes|nullable|string',
            'sender_email' => 'required|string',
            'subject' => 'required|string|min:1|max:255',
            'body' => 'sometimes|nullable|string',
        ]);

        $customerId = IncidentService::tryMatchCustomerByEmail($user->company_id, $data['sender_email']);
        $fallbackReason = null;
        $contract = null;
        if ($customerId === null) {
            $fallbackReason = "No Company/Individual matches sender email {$data['sender_email']}.";
        } else {
            $contract = IncidentService::findValidContract($user->company_id, $customerId);
            if ($contract === null) {
                $fallbackReason = 'No active contract found for this customer.';
            }
        }

        $incident = IncidentService::createIncident(
            companyId: $user->company_id,
            customerId: $customerId,
            source: Incident::SOURCE_EMAIL,
            subject: $data['subject'],
            description: $data['body'] ?? null,
            senderName: $data['sender_name'] ?? null,
            senderEmail: $data['sender_email'],
            senderPhone: null,
            createdByUserId: $user->id,
        );

        if ($fallbackReason !== null) {
            return response()->json([
                'incident' => $this->present($incident->fresh()),
                'job_order_created' => false,
                'fallback_reason' => $fallbackReason,
            ]);
        }

        IncidentService::convertToJobOrder($incident, $contract->id, JobOrder::PRIORITY_NORMAL, $user->id);

        return response()->json([
            'incident' => $this->present($incident->fresh()),
            'job_order_created' => true,
            'fallback_reason' => null,
        ]);
    }

    public function setCustomer(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate(['customer_id' => 'required|uuid']);
        $inc = $this->incidentOrFail($user->company_id, $incident);
        $old = $inc->customer_id;
        $inc->customer_id = $data['customer_id'];
        Audit::record(
            'incident', $inc->id, 'customer_set', $user->id,
            oldValue: ['customer_id' => $old],
            newValue: ['customer_id' => $data['customer_id']],
        );
        $inc->save();

        return response()->json($this->present($inc->fresh()));
    }

    public function callback(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate(['assigned_to_user_id' => 'required|uuid']);
        $inc = $this->incidentOrFail($user->company_id, $incident);
        try {
            IncidentService::setCallback($inc, $data['assigned_to_user_id'], $user->id);
        } catch (IncidentRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }
        $inc->save();

        return response()->json($this->present($inc->fresh()));
    }

    public function close(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate(['reason' => 'required|string|min:1|max:500']);
        $inc = $this->incidentOrFail($user->company_id, $incident);
        try {
            IncidentService::closeIncident($inc, $data['reason'], $user->id);
        } catch (IncidentRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }
        $inc->save();

        return response()->json($this->present($inc->fresh()));
    }

    public function convertToQuotation(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate(['quotation_date' => 'required|date']);
        $inc = $this->incidentOrFail($user->company_id, $incident);
        try {
            $quotation = IncidentService::convertToQuotation($inc, $data['quotation_date'], $user->id);
        } catch (IncidentRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->presentQuotation($quotation->fresh('lines')));
    }

    public function convertToJobOrder(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'contract_id' => 'required|uuid',
            'priority' => 'sometimes|in:low,normal,high,critical',
        ]);
        $inc = $this->incidentOrFail($user->company_id, $incident);
        try {
            $jobOrder = IncidentService::convertToJobOrder(
                $inc, $data['contract_id'], $data['priority'] ?? JobOrder::PRIORITY_NORMAL, $user->id
            );
        } catch (IncidentRuleViolation $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->presentJobOrder($jobOrder->fresh()));
    }
}
