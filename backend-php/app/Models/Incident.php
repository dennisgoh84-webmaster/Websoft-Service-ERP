<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incident Module (2026-09-12, docs/planned-work.md #2 ->
 * docs/open-business-decisions.md #36). Mirrors
 * backend/app/models/incidents.py's Incident exactly -- see that
 * file's docstring for the full rationale.
 *
 * An Incident is the front door for an incoming call or email before
 * it becomes real work: Support Staff (or the Outlook Add-in, on
 * Dennis's behalf) logs it, then routes it to one of a Sales
 * Quotation, a Job Order, or a Software Task -- each conversion
 * auto-creates that real record, pre-filled from the Incident, with a
 * back-reference here (confirmed 2026-09-11: this is not just an
 * assignment/routing flag, the target record actually gets created).
 * An Incident that turns out to need someone to just call back doesn't
 * spin up a separate task record -- it's a status + assignee on the
 * Incident itself (confirmed 2026-09-12).
 *
 * Lives under the existing "service_operations" module (already
 * labelled "Helpdesk / Service Operations (Job Orders)" in the module
 * catalog) rather than a new module key, since an Incident is exactly
 * the helpdesk front door for that same area.
 *
 * KNOWN GAP (see docs/php-conversion-plan.md): raised_by_portal_user_id
 * and converted_software_task_id have no FK constraint and no
 * backend-php code path ever sets them -- the Customer Helpdesk Portal
 * and Software Tasks modules aren't converted yet.
 */
class Incident extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    public const SOURCE_PHONE = 'phone';

    public const SOURCE_EMAIL = 'email';

    public const SOURCE_OTHER = 'other';

    // Raised by a customer through the Helpdesk Portal (PORTAL-002).
    public const SOURCE_PORTAL = 'portal';

    public const STATUS_OPEN = 'open'; // just logged, not yet routed

    public const STATUS_PENDING_CALLBACK = 'pending_callback'; // assigned to call the customer back

    public const STATUS_CONVERTED = 'converted'; // routed to a Quotation/Job Order/Software Task

    public const STATUS_CLOSED = 'closed'; // resolved without needing to convert to anything

    protected $fillable = [
        'company_id', 'incident_number', 'customer_id', 'source', 'subject', 'description',
        'sender_name', 'sender_email', 'sender_phone', 'status', 'assigned_to_user_id',
        'raised_by_portal_user_id', 'converted_quotation_id', 'converted_job_order_id',
        'converted_software_task_id', 'close_reason', 'closed_at', 'closed_by_user_id',
        'created_by_user_id',
    ];

    // Mirrors the DB column defaults (see the migration) so a freshly
    // constructed, not-yet-saved/refreshed Incident behaves the same
    // as one just reloaded from the database.
    protected $attributes = [
        'source' => self::SOURCE_PHONE,
        'status' => self::STATUS_OPEN,
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CompanyIndividual::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function convertedQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'converted_quotation_id');
    }

    public function convertedJobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class, 'converted_job_order_id');
    }
}
