<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/accounting.py's Account/JournalEntry/
// JournalLine, backend/app/models/treasury.py's BankAccount/
// BankTransaction, and backend/app/models/periods.py's
// AccountingPeriod/PeriodLock. See docs/gl-posting-design.md.
//
// NOT included (scoped out, tracked in docs/php-conversion-plan.md):
// GLType (a purely optional reporting sub-classification -- nothing
// in posting.py reads it), BankReconciliation, CurrencyRate,
// FiscalYearClosure. Accounting Periods are created here as a schema
// only -- no period-management endpoints exist yet, so a period is
// never actually created or locked through backend-php/, which makes
// App\Services\Periods::requireAllows() correctly a no-op today (see
// that class's docblock: "opt-in protection, a date with no period
// defined is unrestricted" -- the exact Python behaviour, just never
// exercised until Period management itself is converted).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('account_type', 20); // asset|liability|equity|revenue|expense
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'code'], 'uq_account_code');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('voucher_number', 50);
            $table->string('voucher_type', 20)->default('journal'); // journal|receipt|payment|sales_invoice|purchase_invoice
            $table->date('entry_date');
            $table->string('narration', 500);
            $table->string('status', 20)->default('draft'); // draft|posted|reversed
            $table->string('source_type', 50)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('reverses_entry_id')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('posted_by_user_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->foreign('posted_by_user_id')->references('id')->on('users');
            $table->index('voucher_number');
        });

        // Self-referencing FK, added separately -- Laravel's Postgres
        // grammar emits ADD PRIMARY KEY after ADD FOREIGN KEY within one
        // Schema::create() blueprint, which breaks a self-reference (same
        // fix as the Contracts migration's renewed_from_contract_id).
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reverses_entry_id')->references('id')->on('journal_entries');
        });

        // A document has at most one *live* posting (gl-posting-design.md
        // §4.7): partial unique index over non-reversed, non-null-source
        // rows. Laravel's schema builder has no fluent partial-index
        // helper, so this is raw DDL, same pattern as the CHECK
        // constraints elsewhere in this codebase.
        DB::statement(
            'create unique index uq_journal_entries_live_source on journal_entries (source_type, source_id) '.
            "where status <> 'reversed' and source_type is not null"
        );

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entry_id');
            $table->uuid('account_id');
            $table->decimal('debit_sgd', 12, 2)->default(0);
            $table->decimal('credit_sgd', 12, 2)->default(0);
            $table->string('description', 500)->nullable();

            $table->foreign('entry_id')->references('id')->on('journal_entries');
            $table->foreign('account_id')->references('id')->on('accounts');
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('bank_name', 150);
            $table->string('account_name', 150);
            $table->string('account_number', 50);
            $table->string('branch', 150)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('currency_code', 3)->default('SGD');
            $table->uuid('gl_account_id')->nullable();
            $table->decimal('opening_balance_sgd', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('gl_account_id')->references('id')->on('accounts');
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('bank_account_id');
            $table->string('transaction_number', 50);
            $table->date('transaction_date');
            $table->string('description', 500);
            $table->string('reference', 200)->nullable();
            $table->string('source_type', 50)->nullable();
            $table->uuid('source_id')->nullable();
            $table->decimal('debit_sgd', 14, 2)->default(0);
            $table->decimal('credit_sgd', 14, 2)->default(0);
            $table->boolean('is_reconciled')->default(false);
            $table->timestampTz('reconciled_at')->nullable();
            $table->boolean('is_voided')->default(false);
            $table->string('void_reason', 500)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->integer('fiscal_year');
            $table->string('name', 50);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('open'); // open|closed
            $table->uuid('closed_by_user_id')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('closed_by_user_id')->references('id')->on('users');
            $table->unique(['company_id', 'period_start'], 'uq_accounting_period_start');
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('period_id');
            $table->string('doc_type', 30); // sales_invoice|receipt_voucher|payment_voucher|purchase_bill|journal_voucher
            $table->string('operation', 20); // update|reverse|bank|unbank|gl|ungl
            $table->boolean('is_locked')->default(false);
            $table->uuid('locked_by_user_id')->nullable();
            $table->timestampTz('locked_at')->nullable();

            $table->foreign('period_id')->references('id')->on('accounting_periods');
            $table->foreign('locked_by_user_id')->references('id')->on('users');
            $table->unique(['period_id', 'doc_type', 'operation'], 'uq_period_lock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_locks');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
