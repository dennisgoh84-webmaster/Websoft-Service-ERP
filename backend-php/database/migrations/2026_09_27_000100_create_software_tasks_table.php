<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Software Task. Mirrors backend/app/models/software_tasks.py.
 *
 * Confirmed 2026-09-10 as a minimal first slice (Dennis: "enough for a
 * start, we can extend again later"): no status workflow beyond
 * `is_tested`, deliberately, pending real usage.
 *
 * Also adds the foreign key `incidents.converted_software_task_id`,
 * which that migration deferred explicitly "until software_tasks
 * exists" -- the same pattern used for portal_users and
 * reference_codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            // Free text -- which module(s)/report(s) this touches. No
            // formal software-module catalog exists in this system.
            $table->string('modules_affected', 500)->nullable();
            $table->uuid('assigned_programmer_id')->nullable();
            $table->date('programming_finish_date')->nullable();
            // Manually keyed, not derived from any time logging: there
            // is no timesheet concept for programming work, unlike
            // Service Records for support hours.
            $table->decimal('programming_hours', 8, 2)->nullable();
            $table->uuid('tester_user_id')->nullable();
            $table->boolean('is_tested')->default(false);
            $table->timestampTz('tested_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('assigned_programmer_id')->references('id')->on('users');
            $table->foreign('tester_user_id')->references('id')->on('users');
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'is_tested']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->foreign('converted_software_task_id')->references('id')->on('software_tasks');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['converted_software_task_id']);
        });
        Schema::dropIfExists('software_tasks');
    }
};
