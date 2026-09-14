<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer Helpdesk Portal (PORTAL-001..004, docs/customer-portal-design.md
// §3). Mirrors backend/app/models/portal.py's PortalUser exactly.
//
// This migration also closes the two "no FK yet" gaps earlier
// migrations deliberately left open, both waiting on exactly this
// table to exist (see their own comments):
//   - login_otps.portal_user_id  -> portal_users.id
//   - incidents.raised_by_portal_user_id -> portal_users.id
// Both are real ForeignKey columns in the Python models
// (backend/app/models/core.py's LoginOtp, backend/app/models/incidents.py's
// Incident), so adding the constraints here -- additively, in a new
// migration file, never by editing an applied one -- brings the PHP
// schema back to parity rather than leaving a permanently unconstrained
// column behind.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id'); // tenant
            $table->uuid('contact_id');
            // Copied from the Contact when access is enabled; the
            // Contact's email can change later without silently
            // changing the login.
            $table->string('email', 255);
            $table->string('hashed_password', 255);
            $table->boolean('is_active')->default(true);
            // Invite flow: the temporary password must be replaced at
            // first login.
            $table->boolean('must_change_password')->default(true);
            // 5 wrong passwords lock the login for 15 minutes (design §4).
            $table->integer('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            // The staff member who enabled access -- part of the audit trail.
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('contact_id')->references('id')->on('contacts');
            $table->foreign('created_by_user_id')->references('id')->on('users');

            // One login per contact (PORTAL-001); one email per company
            // so a login attempt resolves to exactly one person.
            $table->unique(['contact_id'], 'uq_portal_user_contact');
            $table->unique(['company_id', 'email'], 'uq_portal_user_company_email');
        });

        Schema::table('login_otps', function (Blueprint $table) {
            $table->foreign('portal_user_id')->references('id')->on('portal_users');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->foreign('raised_by_portal_user_id')->references('id')->on('portal_users');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['raised_by_portal_user_id']);
        });
        Schema::table('login_otps', function (Blueprint $table) {
            $table->dropForeign(['portal_user_id']);
        });
        Schema::dropIfExists('portal_users');
    }
};
