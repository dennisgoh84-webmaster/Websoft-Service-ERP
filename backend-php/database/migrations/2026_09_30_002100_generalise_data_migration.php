<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Migration module (Maintenance -> Data Migration,
 * docs/data-migration.md), built 2026-09-25 on the Odoo import engine
 * of the previous migration. It now takes data from two old systems,
 * ODOO and ZSOFT, so the tables lose their "odoo_" names:
 *
 * - odoo_import_runs -> migration_batches: one row per uploaded file,
 *   through its whole life (uploaded -> dry run -> imported -> rolled
 *   back), with the stored file, its column headings, the field
 *   mapping and duplicate decisions used, live progress, and the
 *   roll-back record. Never deleted.
 * - odoo_record_map -> migration_record_map: which record each source
 *   row became, now per source system. `action` says whether the row
 *   CREATED the record or LINKED to one already here (a Company /
 *   Individual with the same UEN) -- roll back only ever removes what
 *   the batch created. A rolled-back row is kept, stamped
 *   rolled_back_at, and stops counting, so the same source row can be
 *   imported again: hence the partial unique index.
 * - migration_mappings: the saved field mapping per Internal Company,
 *   source system and module, and its Field Gap sign-off (Dennis,
 *   2026-09-25: imports stay locked until every column is either
 *   mapped or deliberately left out, and signed off).
 * - invoices/payments.odoo_imported_at -> migrated_at, since a ZSOFT
 *   past invoice is migrated history too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('odoo_import_runs', 'migration_batches');
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->string('source', 10)->default('odoo');
            $table->string('batch_number', 30)->nullable();
            $table->string('stored_path')->nullable();
            $table->string('sheet_name')->nullable();
            $table->jsonb('headers')->nullable();
            $table->jsonb('sample_row')->nullable(); // first data row, shown beside each column when mapping
            $table->jsonb('mapping')->nullable();
            $table->jsonb('decisions')->nullable();
            $table->integer('rows_linked')->default(0);
            $table->integer('rows_needs_decision')->default(0);
            $table->integer('progress_done')->default(0);
            $table->integer('progress_total')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestampTz('rolled_back_at')->nullable();
            $table->uuid('rolled_back_by_user_id')->nullable();
            $table->text('rollback_reason')->nullable();
            $table->jsonb('rollback_report')->nullable();

            $table->foreign('rolled_back_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'source', 'entity']);
        });
        DB::statement('alter table migration_batches alter column mode drop not null');

        Schema::rename('odoo_record_map', 'migration_record_map');
        Schema::table('migration_record_map', function (Blueprint $table) {
            $table->dropUnique('uq_odoo_record_map_ref');
        });
        Schema::table('migration_record_map', function (Blueprint $table) {
            $table->renameColumn('import_run_id', 'batch_id');
            $table->renameColumn('odoo_ref', 'source_ref');
        });
        Schema::table('migration_record_map', function (Blueprint $table) {
            $table->string('source', 10)->default('odoo');
            $table->string('action', 10)->default('created'); // created|linked
            $table->timestampTz('rolled_back_at')->nullable();
            $table->index('batch_id');
        });
        DB::statement(
            'create unique index uq_migration_record_map_live on migration_record_map (company_id, source, entity, source_ref) where rolled_back_at is null'
        );
        // The Odoo engine called Company / Individual rows "contacts".
        DB::table('migration_record_map')->where('entity', 'contacts')->update(['entity' => 'company_individuals']);

        Schema::create('migration_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('source', 10);
            $table->string('entity', 30);
            // source column heading => target field key, "__skip__"
            // (left out on purpose) or "__new_field__" (needs a field
            // added here first -- a Field Gap).
            $table->jsonb('mapping');
            $table->jsonb('headers')->nullable();
            $table->timestampTz('signed_off_at')->nullable();
            $table->uuid('signed_off_by_user_id')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('signed_off_by_user_id')->references('id')->on('users');
            $table->unique(['company_id', 'source', 'entity'], 'uq_migration_mapping');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('odoo_imported_at', 'migrated_at');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('odoo_imported_at', 'migrated_at');
        });

        // Existing installations are upgraded by migrations alone (the
        // seeder only runs on a fresh install), so the module key has
        // to arrive here too. Not switched on for any company: the
        // owner sees it regardless, and anyone else once it is enabled
        // under Module Control.
        DB::table('modules')->insertOrIgnore([
            'key' => 'data_migration',
            'name' => 'Data Migration (ODOO / ZSOFT)',
            'description' => 'Bring data in from the old ODOO and ZSOFT systems: upload, map fields, dry run, import, roll back.',
            'is_built' => true,
        ]);
    }

    public function down(): void
    {
        DB::table('group_module_authorities')->where('module_key', 'data_migration')->delete();
        DB::table('company_modules')->where('module_key', 'data_migration')->delete();
        DB::table('modules')->where('key', 'data_migration')->delete();
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('migrated_at', 'odoo_imported_at');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('migrated_at', 'odoo_imported_at');
        });
        Schema::dropIfExists('migration_mappings');

        DB::statement('drop index if exists uq_migration_record_map_live');
        Schema::table('migration_record_map', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['source', 'action', 'rolled_back_at']);
        });
        Schema::table('migration_record_map', function (Blueprint $table) {
            $table->renameColumn('batch_id', 'import_run_id');
            $table->renameColumn('source_ref', 'odoo_ref');
        });
        DB::table('migration_record_map')->where('entity', 'company_individuals')->update(['entity' => 'contacts']);
        Schema::table('migration_record_map', function (Blueprint $table) {
            $table->unique(['company_id', 'entity', 'odoo_ref'], 'uq_odoo_record_map_ref');
        });
        Schema::rename('migration_record_map', 'odoo_record_map');

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropForeign(['rolled_back_by_user_id']);
            $table->dropIndex(['company_id', 'source', 'entity']);
            $table->dropColumn([
                'source', 'batch_number', 'stored_path', 'sheet_name', 'headers', 'sample_row', 'mapping', 'decisions',
                'rows_linked', 'rows_needs_decision', 'progress_done', 'progress_total', 'error_message',
                'imported_at', 'rolled_back_at', 'rolled_back_by_user_id', 'rollback_reason', 'rollback_report',
            ]);
        });
        Schema::rename('migration_batches', 'odoo_import_runs');
    }
};
