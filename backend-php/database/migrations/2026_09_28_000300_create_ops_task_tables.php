<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My Ops Dashboard: a personal, freeform task tracker per staff member.
 * Mirrors backend/app/models/ops_tasks.py (confirmed 2026-09-11).
 *
 * Deliberately freeform: `owner_label`, `due_label` and
 * `cadence_label` are free TEXT, not links or dates, because this is a
 * personal working board rather than a second scheduling system. The
 * one structured follow-up (`follow_up_staff_id` + `follow_up_date`)
 * exists so a task can actually chase someone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_task_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('owner_user_id');
            $table->string('name', 255);
            // Free text such as "Daily" or "Every Monday" -- a label,
            // not a schedule the system acts on.
            $table->string('cadence_label', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('owner_user_id')->references('id')->on('users');
            $table->index(['company_id', 'owner_user_id', 'is_active']);
        });

        Schema::create('ops_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('category_id');
            $table->uuid('owner_user_id');
            $table->string('title', 500);
            // not_started | in_progress | watch | blocked | done
            $table->string('status', 20)->default('not_started');
            $table->string('next_action', 500)->nullable();
            $table->string('owner_label', 255)->nullable();
            $table->string('due_label', 100)->nullable();
            $table->uuid('follow_up_staff_id')->nullable();
            $table->date('follow_up_date')->nullable();
            // Seeded example rows, so a new dashboard is not empty.
            $table->boolean('is_sample')->default(false);
            // Archiving sets this false -- a task is never deleted.
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('category_id')->references('id')->on('ops_task_categories');
            $table->foreign('owner_user_id')->references('id')->on('users');
            $table->foreign('follow_up_staff_id')->references('id')->on('users');
            $table->index(['company_id', 'owner_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_tasks');
        Schema::dropIfExists('ops_task_categories');
    }
};
