<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission Payouts -- mirrors backend/app/models/commissions.py's
 * CommissionPayout (open-business-decisions 6.3 approval, 6.4
 * clawback, 6.5 payout).
 *
 * One record per salesperson per month, turning the Commission
 * report's calculation into something discrete, approvable and
 * payable. `amount_sgd` is positive for an EARNING and negative for a
 * CLAWBACK, so a salesperson's net position is a plain sum over their
 * rows and a reversal is never a delete.
 *
 * Statuses are plain strings rather than a Postgres enum type, as
 * everywhere else in `backend-php` -- adding a value to a native enum
 * needs a migration that cannot run inside a transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('payout_number', 50)->index();
            $table->string('payout_type', 20)->default('earning');
            $table->string('status', 30)->default('draft');
            $table->uuid('sales_staff_id');
            $table->string('period_month', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount_sgd', 12, 2);
            $table->decimal('rate_percent', 5, 2);
            $table->uuid('clawback_invoice_id')->nullable();
            $table->string('clawback_reason', 500)->nullable();
            $table->uuid('submitted_by_user_id')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->date('paid_date')->nullable();
            $table->string('paid_reference', 200)->nullable();
            $table->uuid('paid_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('sales_staff_id')->references('id')->on('users');
            $table->foreign('clawback_invoice_id')->references('id')->on('invoices');
            $table->foreign('submitted_by_user_id')->references('id')->on('users');
            $table->foreign('approved_by_user_id')->references('id')->on('users');
            $table->foreign('paid_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payouts');
    }
};
