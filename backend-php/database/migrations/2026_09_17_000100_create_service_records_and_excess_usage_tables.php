<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/service_records.py's ServiceRecord and
// backend/app/models/contracts.py's ExcessUsageRecord -- the latter
// was deferred from the Contracts migration specifically because it
// references service_records (see that migration's docblock); it
// belongs here now that this table exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('job_order_id');
            $table->uuid('employee_user_id');
            $table->string('service_record_number', 50);
            $table->date('work_date');
            $table->integer('raw_minutes');
            $table->integer('rounded_minutes'); // SRV-007
            $table->string('status', 20)->default('submitted'); // submitted|approved
            $table->string('outcome', 30)->default('pending'); // pending|contract_deduction|excess_usage|not_hour_metered
            $table->string('completion_status', 1)->default('U'); // C|U
            $table->boolean('is_after_hours')->default(false);
            $table->integer('deducted_minutes')->nullable();
            $table->text('work_description')->nullable();
            $table->timestampTz('time_in')->nullable();
            $table->timestampTz('time_out')->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('job_order_id')->references('id')->on('job_orders');
            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('approved_by_user_id')->references('id')->on('users');
            $table->index('service_record_number');
        });

        Schema::create('excess_usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('contract_id');
            $table->uuid('service_record_id');
            $table->integer('excess_minutes');
            $table->string('treatment', 30)->nullable(); // billable|approved_non_billable|warranty_goodwill|internal_write_off|other
            $table->text('reason')->nullable();
            $table->uuid('decided_by_user_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->boolean('invoiced')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->foreign('service_record_id')->references('id')->on('service_records');
            $table->foreign('decided_by_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_usage_records');
        Schema::dropIfExists('service_records');
    }
};
