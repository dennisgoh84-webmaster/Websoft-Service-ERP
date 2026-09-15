<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors backend/app/models/payments.py's CommissionSettings: one row
 * per company holding the commission rate the Commission report
 * applies (docs/open-business-decisions.md #34).
 *
 * The rate is Dennis's to set, so it is stored rather than hardcoded,
 * and it starts at zero -- a report against an unset rate reports zero
 * commission, never an invented percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settings', function (Blueprint $table) {
            $table->uuid('company_id')->primary();
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settings');
    }
};
