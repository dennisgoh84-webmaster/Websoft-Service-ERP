<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/periods.py's FiscalYearClosure. Additive
// migration -- accounting_periods/period_locks already exist (created
// alongside the GL posting + Bank tables migration, unused by any
// controller until this Accounting Period management conversion).
// See docs/php-conversion-plan.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_year_closures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->integer('fiscal_year');
            $table->uuid('retained_earnings_account_id');
            $table->uuid('closing_journal_entry_id');
            $table->uuid('closed_by_user_id')->nullable();
            $table->timestampTz('closed_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('retained_earnings_account_id')->references('id')->on('accounts');
            $table->foreign('closing_journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('closed_by_user_id')->references('id')->on('users');
            $table->unique(['company_id', 'fiscal_year'], 'uq_fiscal_year_closure');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_closures');
    }
};
