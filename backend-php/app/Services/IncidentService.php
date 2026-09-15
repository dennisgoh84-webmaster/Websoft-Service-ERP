<?php

namespace App\Services;

use App\Exceptions\IncidentRuleViolation;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\SoftwareTask;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Incident Module business logic (2026-09-12, see App\Models\Incident's
 * docstring and docs/open-business-decisions.md #36 for the confirmed
 * rules this implements). Mirrors backend/app/services/incidents.py
 * function-for-function -- kept as plain static methods, shared by
 * App\Http\Controllers\Api\IncidentController (the in-app screen) and
 * the Outlook Add-in endpoints on the same controller, so both paths
 * convert an Incident the exact same way.
 *
 * KNOWN GAP: convert_to_software_task (backend/app/services/incidents.py)
 * is NOT ported here -- the Software Tasks module (backend/app/models/
 * software_tasks.py) has no backend-php equivalent yet, so there is no
 * model to create against. See docs/php-conversion-plan.md's Incidents
 * entry; App\Http\Controllers\Api\IncidentController has no
 * convert-to-software-task action and no route is registered for it,
 * the same "don't silently drop, don't invent around it, list it as a
 * known gap" pattern used for every other real dependency gap in this
 * conversion.
 */
class IncidentService
{
    // A Job Order can be raised against a contract that's still within
    // its term even if its hours are used up (SRV-001/SRV-008's
    // excess-usage path exists for exactly that) -- so "valid" here
    // means ACTIVE or EXCEEDED, not DRAFT (never activated), EXPIRED,
    // or RENEWED (superseded by a newer contract).
    public const VALID_CONTRACT_STATUSES = [Contract::STATUS_ACTIVE, Contract::STATUS_EXCEEDED];

    /**
     * The one automatic customer match this system attempts: a case-
     * insensitive match against an existing Contact's email address on
     * a Company/Individual belonging to this company. No match ->
     * null, left for a human to set (see IncidentController::setCustomer())
     * -- never guessed at more aggressively than an exact email match.
     */
    public static function tryMatchCustomerByEmail(string $companyId, ?string $email): ?string
    {
        if (! $email) {
            return null;
        }

        $contact = Contact::query()
            ->join('company_individuals', 'contacts.customer_id', '=', 'company_individuals.id')
            ->where('company_individuals.company_id', $companyId)
            ->whereRaw('lower(contacts.email) = ?', [strtolower($email)])
            ->select('contacts.*')
            ->first();

        return $contact?->customer_id;
    }

    /**
     * Most-recently-started contract still valid for raising a new Job
     * Order against (see VALID_CONTRACT_STATUSES above). Null if the
     * customer has no such contract.
     */
    public static function findValidContract(string $companyId, string $customerId): ?Contract
    {
        return Contract::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->whereIn('status', self::VALID_CONTRACT_STATUSES)
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * `raisedByPortalUserId`/`portalActorName` are set only when a
     * customer raised this themselves through the Helpdesk Portal
     * (PORTAL-002) -- `createdByUserId` stays null in that case (no
     * staff member created it), so the audit trail is told the actor's
     * name directly rather than looking one up on User (see
     * App\Services\Audit's docstring on those two params). Passed by
     * App\Http\Controllers\Api\PortalController::createIncident(), so
     * the Outlook Add-in and Portal paths share this exact function,
     * same as the Python source's own design intent.
     */
    public static function createIncident(
        string $companyId,
        ?string $customerId,
        string $source,
        string $subject,
        ?string $description,
        ?string $senderName,
        ?string $senderEmail,
        ?string $senderPhone,
        ?string $createdByUserId,
        ?string $raisedByPortalUserId = null,
        ?string $portalActorName = null,
    ): Incident {
        $incident = Incident::create([
            'company_id' => $companyId,
            'incident_number' => Numbering::next($companyId, 'incident'),
            'customer_id' => $customerId,
            'source' => $source,
            'subject' => $subject,
            'description' => $description,
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'sender_phone' => $senderPhone,
            'created_by_user_id' => $createdByUserId,
            'raised_by_portal_user_id' => $raisedByPortalUserId,
        ]);

        Audit::record(
            'incident', $incident->id, 'created', $createdByUserId,
            actorName: $portalActorName,
            companyId: $raisedByPortalUserId ? $companyId : null,
            details: "{$incident->incident_number}: {$subject}",
            newValue: ['source' => $source, 'customer_id' => $customerId],
        );

        return $incident;
    }

    /**
     * Tell the person whose email became this Incident (and, when the
     * Outlook Add-in also opened one, this Job Order) that it has been
     * logged, from the Helpdesk mailbox (Dennis, 2026-09-15: a separate
     * system mailbox "for MS Outlook add in to convert to Incident/Job
     * Order").
     *
     * Returns whether it went out. It NEVER fails the conversion: an
     * unconfigured mailbox or a refused send leaves the Incident and
     * Job Order exactly as created and reports false, and the audit
     * trail records the send only when it happened. The add-in shows
     * the result either way.
     */
    public static function sendAcknowledgement(Incident $incident, ?JobOrder $jobOrder, ?string $actorUserId): bool
    {
        $to = trim((string) $incident->sender_email);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || ! Mailer::isHelpdeskConfigured()) {
            return false;
        }

        $greeting = $incident->sender_name ? "Hi {$incident->sender_name}," : 'Hello,';
        $lines = [
            $greeting,
            '',
            "Thank you for your email \"{$incident->subject}\". We have logged it as {$incident->incident_number}.",
        ];
        if ($jobOrder) {
            $lines[] = "A Job Order, {$jobOrder->job_order_number}, has been opened for it and our support team will be in touch.";
        } else {
            $lines[] = 'Our support team will review it and be in touch.';
        }
        $lines[] = '';
        $lines[] = 'Please quote the reference above in any reply.';

        try {
            Mailer::sendFromHelpdesk(
                $to,
                "[{$incident->incident_number}] We have received your request: {$incident->subject}",
                implode("\n", $lines),
            );
        } catch (MailerNotConfiguredException|MailerException) {
            return false;
        }

        Audit::record(
            'incident', $incident->id, 'acknowledgement_emailed', $actorUserId,
            details: "{$incident->incident_number}: acknowledgement sent to {$to}"
                .($jobOrder ? " for {$jobOrder->job_order_number}" : ''),
        );

        return true;
    }

    private static function requireOpen(Incident $incident): void
    {
        if ($incident->status !== Incident::STATUS_OPEN) {
            throw new IncidentRuleViolation(
                "Incident {$incident->incident_number} is already {$incident->status}, not open."
            );
        }
    }

    public static function setCallback(Incident $incident, string $assignedToUserId, ?string $actorUserId): Incident
    {
        self::requireOpen($incident);
        $incident->status = Incident::STATUS_PENDING_CALLBACK;
        $incident->assigned_to_user_id = $assignedToUserId;
        Audit::record(
            'incident', $incident->id, 'pending_callback', $actorUserId,
            newValue: ['assigned_to_user_id' => $assignedToUserId],
        );

        return $incident;
    }

    public static function closeIncident(Incident $incident, string $reason, ?string $actorUserId): Incident
    {
        if ($incident->status === Incident::STATUS_CONVERTED) {
            throw new IncidentRuleViolation("Incident {$incident->incident_number} was already converted.");
        }
        if ($incident->status === Incident::STATUS_CLOSED) {
            throw new IncidentRuleViolation("Incident {$incident->incident_number} is already closed.");
        }
        $incident->status = Incident::STATUS_CLOSED;
        $incident->close_reason = $reason;
        $incident->closed_at = Carbon::now();
        $incident->closed_by_user_id = $actorUserId;
        Audit::record(
            'incident', $incident->id, 'closed', $actorUserId,
            newValue: ['close_reason' => $reason],
        );

        return $incident;
    }

    public static function convertToQuotation(Incident $incident, string $quotationDate, ?string $actorUserId): Quotation
    {
        self::requireOpen($incident);
        if ($incident->customer_id === null) {
            throw new IncidentRuleViolation('Set a Company/Individual on this Incident before converting to a Quotation.');
        }

        return DB::transaction(function () use ($incident, $quotationDate, $actorUserId) {
            $quotation = Quotation::create([
                'company_id' => $incident->company_id,
                'quotation_number' => Numbering::next($incident->company_id, 'quotation'),
                'customer_id' => $incident->customer_id,
                'quotation_date' => $quotationDate,
                'notes' => "From Incident {$incident->incident_number}: {$incident->subject}",
                'created_by_user_id' => $actorUserId,
            ]);

            // A Quotation needs at least one line -- a placeholder
            // Sales can price properly, since an Incident only carries
            // a subject/description, never product/price detail.
            QuotationLine::create([
                'quotation_id' => $quotation->id,
                'description' => $incident->subject,
                'quantity' => Money::of(1)->toString(),
                'unit_price_sgd' => Money::of(0)->toString(),
                'line_total_sgd' => Money::of(0)->toString(),
            ]);

            $incident->status = Incident::STATUS_CONVERTED;
            $incident->converted_quotation_id = $quotation->id;
            $incident->save();

            Audit::record(
                'incident', $incident->id, 'converted_to_quotation', $actorUserId,
                newValue: ['quotation_id' => $quotation->id],
            );

            return $quotation;
        });
    }

    /**
     * Route an Incident to the Software Development queue. Mirrors
     * backend/app/services/incidents.py's convert_to_software_task.
     *
     * Unlike the Quotation and Job Order routes this needs no customer
     * and no contract -- a bug report is a bug report whether or not
     * the caller was ever identified, which is why Python checks
     * neither. Only that the Incident is still open.
     */
    public static function convertToSoftwareTask(Incident $incident, ?string $assignedProgrammerId, ?string $actorUserId): SoftwareTask
    {
        self::requireOpen($incident);

        return DB::transaction(function () use ($incident, $assignedProgrammerId, $actorUserId) {
            $task = SoftwareTask::create([
                'company_id' => $incident->company_id,
                'title' => $incident->subject,
                'description' => $incident->description,
                'assigned_programmer_id' => $assignedProgrammerId,
                'created_by_user_id' => $actorUserId,
            ]);

            $incident->status = Incident::STATUS_CONVERTED;
            $incident->converted_software_task_id = $task->id;
            $incident->save();

            Audit::record(
                entityType: 'incident',
                entityId: $incident->id,
                action: 'converted_to_software_task',
                actorUserId: $actorUserId,
                newValue: ['software_task_id' => $task->id],
            );

            return $task;
        });
    }

    public static function convertToJobOrder(Incident $incident, string $contractId, string $priority, ?string $actorUserId): JobOrder
    {
        self::requireOpen($incident);
        if ($incident->customer_id === null) {
            throw new IncidentRuleViolation('Set a Company/Individual on this Incident before converting to a Job Order.');
        }
        $contract = Contract::find($contractId);
        if ($contract === null || $contract->company_id !== $incident->company_id || $contract->customer_id !== $incident->customer_id) {
            throw new IncidentRuleViolation("That contract doesn't belong to this Incident's Company/Individual.");
        }
        if (! in_array($contract->status, self::VALID_CONTRACT_STATUSES, true)) {
            throw new IncidentRuleViolation(
                "Contract {$contract->contract_number} is {$contract->status}, not a valid contract to raise a Job Order against."
            );
        }

        return DB::transaction(function () use ($incident, $contract, $priority, $actorUserId) {
            $jobOrder = JobOrder::create([
                'company_id' => $incident->company_id,
                'customer_id' => $incident->customer_id,
                'contract_id' => $contract->id,
                'job_order_number' => Numbering::next($incident->company_id, 'job_order'),
                'subject' => $incident->subject,
                // job_order_type isn't set by the Python function
                // either -- it relies on JobOrderType.SUPPORT being
                // the SQLAlchemy column default (backend/app/models/
                // job_orders.py), same value spelled out explicitly
                // here since Eloquent doesn't read DB-level defaults
                // back into a freshly-created in-memory model.
                'job_order_type' => JobOrder::TYPE_SUPPORT,
                'priority' => $priority,
            ]);

            $incident->status = Incident::STATUS_CONVERTED;
            $incident->converted_job_order_id = $jobOrder->id;
            $incident->save();

            Audit::record(
                'incident', $incident->id, 'converted_to_job_order', $actorUserId,
                newValue: ['job_order_id' => $jobOrder->id],
            );

            return $jobOrder;
        });
    }
}
