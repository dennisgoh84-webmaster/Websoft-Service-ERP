<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One completed Bank Reconciliation session. Mirrors
 * backend/app/models/treasury.py's BankReconciliation.
 *
 * `bank_transactions` already existed -- the GL posting + Bank step
 * conversion created it for the Bank step -- but nothing ever recorded
 * a reconciliation session against it.
 *
 * Kept as a permanent history, never edited or deleted, so "when did we
 * last reconcile, and against what statement balance" is always
 * answerable. The two balance columns are SNAPSHOTS taken at save time:
 * the Bank Book keeps moving afterwards, so these are what actually
 * reconciled, not a live query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('bank_account_id');
            $table->date('statement_date');
            $table->decimal('statement_balance_sgd', 14, 2);
            $table->decimal('ledger_balance_sgd', 14, 2);
            $table->decimal('difference_sgd', 14, 2);
            $table->text('note')->nullable();
            $table->uuid('reconciled_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
            $table->foreign('reconciled_by_user_id')->references('id')->on('users');
            $table->index(['bank_account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
