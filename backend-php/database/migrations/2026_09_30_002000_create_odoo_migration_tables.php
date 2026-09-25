<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odoo migration program (docs/planned-work.md #6,
 * docs/data-migration.md -- renamed and generalised by the next
 * migration). Decided with Dennis 2026-09-25: the source
 * is Odoo's own CSV/Excel exports, imported documents keep their Odoo
 * number, and imported invoices/receipts are history only -- they
 * never post to the General Ledger, which is carried across instead by
 * a single opening-balance journal voucher.
 *
 * - `odoo_import_runs`: one row per run of `php artisan odoo:import`,
 *   dry runs included, with its row-by-row report. Never deleted.
 * - `odoo_record_map`: which Websoft record each Odoo record became.
 *   It is what makes a re-run skip rows already imported, and what
 *   later files resolve their references through (an invoice's
 *   `partner_id/id` -> the Company/Individual). Permanent: it is the
 *   audit trail from every migrated record back to its Odoo origin.
 * - `invoices.pre_migration_paid_sgd` / `payments.pre_migration_allocated_sgd`:
 *   what Odoo had already settled before cut-over. An imported invoice
 *   carries Odoo's own paid amount (total - amount_residual) without
 *   any PaymentAllocation rows behind it, so
 *   AccountsReceivableService::recalculateInvoiceStatus() adds this
 *   figure back in rather than letting a later receipt reset the paid
 *   amount to just the allocations made here. Zero on every document
 *   created in this system.
 * - `odoo_imported_at`: marks a migrated invoice/receipt, so the Bank
 *   step can refuse to enter an already-banked Odoo receipt into the
 *   bank book a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('entity', 30);
            $table->string('source_filename');
            $table->string('mode', 10); // dry_run|commit
            $table->string('status', 20)->default('running'); // running|succeeded|failed|dry_run
            $table->integer('rows_read')->default(0);
            $table->integer('rows_created')->default(0);
            $table->integer('rows_already_imported')->default(0);
            $table->integer('rows_skipped')->default(0);
            $table->integer('rows_failed')->default(0);
            $table->jsonb('report')->nullable();
            $table->uuid('started_by_user_id')->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('started_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'entity']);
        });

        Schema::create('odoo_record_map', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('entity', 30);
            $table->string('odoo_ref');
            $table->string('target_type', 50);
            $table->uuid('target_id');
            $table->uuid('import_run_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('import_run_id')->references('id')->on('odoo_import_runs');
            $table->unique(['company_id', 'entity', 'odoo_ref'], 'uq_odoo_record_map_ref');
            $table->index(['target_type', 'target_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('pre_migration_paid_sgd', 12, 2)->default(0);
            $table->timestampTz('odoo_imported_at')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('pre_migration_allocated_sgd', 12, 2)->default(0);
            $table->timestampTz('odoo_imported_at')->nullable();
        });

        DB::statement('alter table invoices add constraint ck_invoice_pre_migration_paid_not_negative check (pre_migration_paid_sgd >= 0)');
        DB::statement('alter table payments add constraint ck_payment_pre_migration_allocated_not_negative check (pre_migration_allocated_sgd >= 0)');
    }

    public function down(): void
    {
        DB::statement('alter table payments drop constraint if exists ck_payment_pre_migration_allocated_not_negative');
        DB::statement('alter table invoices drop constraint if exists ck_invoice_pre_migration_paid_not_negative');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['pre_migration_allocated_sgd', 'odoo_imported_at']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['pre_migration_paid_sgd', 'odoo_imported_at']);
        });
        Schema::dropIfExists('odoo_record_map');
        Schema::dropIfExists('odoo_import_runs');
    }
};
