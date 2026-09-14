<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/incidents.py's Incident exactly -- see
// that file's docstring and docs/open-business-decisions.md #36 for
// the confirmed rules (the Helpdesk front door for an incoming call or
// email, before it's routed to a Sales Quotation, Job Order, or
// Software Task -- or just marked for a callback).
//
// Two FKs are deliberately NOT constrained yet, same "no FK yet"
// pattern already used for reference_code_id elsewhere in this
// codebase, because the tables they'd point at don't exist in
// backend-php yet:
//   - raised_by_portal_user_id -> portal_users (Customer Helpdesk
//     Portal isn't converted -- see docs/php-conversion-plan.md).
//   - converted_software_task_id -> software_tasks (Software Tasks
//     module isn't converted either).
// Both columns are kept on the table for schema fidelity with the
// Python model, but no backend-php code path can ever set either of
// them today -- see docs/php-conversion-plan.md's Incidents entry.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('incident_number', 50);

            // Nullable: an incoming call/email doesn't always identify
            // a matched Company/Individual immediately -- see
            // App\Services\IncidentService::tryMatchCustomerByEmail for
            // the one automatic match this system attempts (against
            // contacts.email). A Quotation/Job Order conversion
            // requires this to be set first.
            $table->uuid('customer_id')->nullable();

            $table->string('source', 20)->default('phone'); // phone|email|other|portal
            $table->string('subject', 255);
            $table->text('description')->nullable();

            // Captured as given, independent of whether customer_id
            // got matched -- especially useful for an Outlook-sourced
            // Incident, where this is exactly what the email itself
            // carries.
            $table->string('sender_name', 255)->nullable();
            $table->string('sender_email', 255)->nullable();
            $table->string('sender_phone', 50)->nullable();

            $table->string('status', 20)->default('open'); // open|pending_callback|converted|closed
            // Set together when status becomes pending_callback
            // (confirmed 2026-09-12: no separate reminder/task record).
            $table->uuid('assigned_to_user_id')->nullable();
            // Set when a customer raised this through the Helpdesk
            // Portal: the person, not just the customer (PORTAL-001
            // audit trail). No FK yet -- see class docblock above.
            $table->uuid('raised_by_portal_user_id')->nullable();

            // Exactly one of these is set once status = converted --
            // which target type this Incident was routed to.
            $table->uuid('converted_quotation_id')->nullable();
            $table->uuid('converted_job_order_id')->nullable();
            // No FK yet -- see class docblock above.
            $table->uuid('converted_software_task_id')->nullable();

            $table->string('close_reason', 500)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();

            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('assigned_to_user_id')->references('id')->on('users');
            $table->foreign('converted_quotation_id')->references('id')->on('quotations');
            $table->foreign('converted_job_order_id')->references('id')->on('job_orders');
            $table->foreign('closed_by_user_id')->references('id')->on('users');
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->index('incident_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
