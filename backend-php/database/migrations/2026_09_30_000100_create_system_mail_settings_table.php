<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The system-level mailboxes move out of .env and into the database
 * (Dennis, 2026-09-15: "SMTP settings to be setup in the backend
 * separately for OTP, and another one for MS Outlook add in to convert
 * to Incident/Job Order").
 *
 * One row per PURPOSE, keyed by it:
 *
 *   otp       -> login one-time codes, password resets, portal invites
 *                and portal password resets. Runs before any company
 *                is chosen, so it can never be a company mailbox.
 *   helpdesk  -> the acknowledgement sent back to the person whose
 *                email the Outlook Add-in turned into an Incident or a
 *                Job Order ("we've logged your request as INC-0001").
 *
 * These are GLOBAL, like announcements and ad_banner_settings, not
 * company-scoped: the OTP mailbox has no company context by
 * definition, and the helpdesk one is the install's support desk.
 * Per-company customer-facing DOCUMENT email stays on the companies
 * table (Company Setup -> Outbound email); nothing here falls back to
 * that or vice versa -- see App\Services\Mailer.
 *
 * This is also the table docs/planned-work.md #8c needed to exist:
 * Central Command pushes configuration by writing into the client's
 * database, which it could never do to .env. .env's SMTP_* keys remain
 * as a fallback for the `otp` purpose only, so an install configured
 * the old way keeps working until its row here is filled in.
 *
 * The password is encrypted at rest through the model's `encrypted`
 * cast and never returned by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_mail_settings', function (Blueprint $table) {
            $table->string('purpose', 20)->primary();
            $table->string('host', 255)->nullable();
            $table->integer('port')->default(587);
            $table->string('username', 255)->nullable();
            // Ciphertext, so wider than the plaintext it holds.
            $table->text('password')->nullable();
            $table->boolean('use_tls')->default(true);
            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_mail_settings');
    }
};
