<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRV-020 (Dennis, 2026-09-15, settling open item 9.2): whether a
 * Service Record's time is contract-covered, billable or non-billable
 * "depends on the job order context" -- so the Job Order carries the
 * classification and staff never choose it per record. Existing Job
 * Orders default to `contract`, which is exactly what they did before
 * (the contract balance decides deduction vs. Excess Usage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('billing_classification', 20)->default('contract'); // contract|billable|non_billable
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('billing_classification');
        });
    }
};
