<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Inbox (Dennis, 2026-09-26): until the server has the public HTTPS
 * address the Outlook / Gmail add-ins need, the server reads the helpdesk
 * mailbox itself over IMAP and lists each new email for staff to Log as
 * Incident, Convert to Job Order or Dismiss.
 *
 * - inbox_emails: one row per email read. Like the mailbox settings it is
 *   global, not per company; the Incident or Job Order made from it
 *   belongs to the company of the staff member who acted. Rows are never
 *   deleted -- a dismissed email keeps its reason.
 * - system_mail_settings gains where reading got to (UIDVALIDITY and the
 *   last UID read) and the last check's time and error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mailbox', 32);
            $table->unsignedBigInteger('uid_validity');
            $table->unsignedBigInteger('uid');
            $table->text('message_id')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email');
            $table->string('subject');
            $table->timestampTz('received_at');
            $table->text('body_text')->nullable();
            $table->json('attachment_names')->nullable();
            $table->string('status', 16)->default('new');
            $table->uuid('company_id')->nullable();
            $table->uuid('incident_id')->nullable();
            $table->uuid('job_order_id')->nullable();
            $table->uuid('handled_by_user_id')->nullable();
            $table->timestampTz('handled_at')->nullable();
            $table->text('dismiss_reason')->nullable();
            $table->timestampTz('fetched_at')->useCurrent();

            $table->unique(['mailbox', 'uid_validity', 'uid']);
            $table->index(['status', 'received_at']);
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('incident_id')->references('id')->on('incidents');
            $table->foreign('job_order_id')->references('id')->on('job_orders');
            $table->foreign('handled_by_user_id')->references('id')->on('users');
        });

        Schema::table('system_mail_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('imap_uid_validity')->nullable();
            $table->unsignedBigInteger('imap_last_uid')->nullable();
            $table->timestampTz('imap_last_checked_at')->nullable();
            $table->text('imap_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_mail_settings', function (Blueprint $table) {
            $table->dropColumn(['imap_uid_validity', 'imap_last_uid', 'imap_last_checked_at', 'imap_last_error']);
        });
        Schema::dropIfExists('inbox_emails');
    }
};
