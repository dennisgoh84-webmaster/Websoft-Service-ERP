<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/job_orders.py's JobOrder and
// ProjectMilestone -- see that file's docstring for the status model
// (auto-close from Service Record approval, not yet converted, so
// nothing sets a job order to CLOSED yet in this backend -- see
// docs/php-conversion-plan.md) and PROJECT-type milestone scheduling.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_id');
            $table->uuid('contract_id')->nullable();
            $table->string('job_order_number', 50);
            $table->string('subject');
            $table->string('job_order_type', 20)->default('support'); // support|project
            $table->string('priority', 20)->default('normal'); // low|normal|high|critical
            $table->string('status', 20)->default('open'); // open|assigned|closed|void
            $table->uuid('assigned_to_user_id')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->boolean('budget_overrun_approved')->default(false);
            $table->uuid('budget_overrun_approved_by')->nullable();
            $table->timestampTz('budget_overrun_approved_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->foreign('assigned_to_user_id')->references('id')->on('users');
            $table->foreign('budget_overrun_approved_by')->references('id')->on('users');
            $table->index('job_order_number');
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_order_id');
            $table->string('milestone_type', 30); // installation|training|repeat_training|handover|completion_signoff
            $table->string('label', 200);
            $table->integer('sort_order')->default(0);
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->uuid('assigned_user_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending|in_progress|completed|skipped
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('job_order_id')->references('id')->on('job_orders');
            $table->foreign('assigned_user_id')->references('id')->on('users');
            $table->index('job_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('job_orders');
    }
};
