<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/core.py's LoginOtp and AuditLogEntry.
// `login_otps.portal_user_id` references the future portal_users table
// (Customer Helpdesk Portal, not yet converted -- see
// docs/php-conversion-plan.md) so it is left as a plain nullable uuid
// without a foreign key for now, same shape the column will need once
// that table exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('portal_user_id')->nullable(); // FK added once portal_users exists
            $table->string('code_hash', 64);
            $table->string('purpose', 20)->default('login'); // login|password_reset
            $table->integer('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('audit_log_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('entity_type', 100);
            $table->uuid('entity_id');
            $table->string('action', 100);
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->text('reason')->nullable();
            $table->text('details')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_id')->nullable();
            $table->timestampTz('at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
        Schema::dropIfExists('login_otps');
    }
};
