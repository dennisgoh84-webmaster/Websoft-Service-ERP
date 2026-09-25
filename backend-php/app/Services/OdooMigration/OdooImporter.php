<?php

namespace App\Services\OdooMigration;

use App\Models\Company;
use App\Models\OdooImportRun;
use App\Models\User;
use App\Services\Audit;
use App\Services\OdooMigration\Importers\AccountsImporter;
use App\Services\OdooMigration\Importers\ContactsImporter;
use App\Services\OdooMigration\Importers\InvoicesImporter;
use App\Services\OdooMigration\Importers\OpeningBalancesImporter;
use App\Services\OdooMigration\Importers\QuotationsImporter;
use App\Services\OdooMigration\Importers\ReceiptsImporter;
use App\Services\OdooMigration\Importers\SubscriptionsImporter;
use App\Services\OdooMigration\Importers\TimesheetsImporter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Odoo migration engine (docs/odoo-migration.md). One call imports
 * one Odoo export file for one record type:
 *
 * - The whole file runs in one transaction, each record in its own
 *   savepoint, so one bad row is reported without taking the rest of
 *   the report down with it.
 * - A dry run does all of it -- every lookup, every constraint -- and
 *   then rolls back, so what it reports is exactly what a commit would
 *   do.
 * - A commit is all or nothing: a single failed row rolls the whole
 *   file back. Skipped rows (drafts, cancellations, credit notes) are
 *   reported and do not block it.
 * - A record already in odoo_record_map is left alone -- never
 *   overwritten, since it may have been edited here since -- so a file
 *   can be re-run safely after fixing the rows that failed.
 *
 * Every run, dry runs included, leaves an odoo_import_runs row and an
 * audit entry.
 */
class OdooImporter
{
    /** In dependency order -- each may resolve references to the ones above it. */
    public const ENTITIES = [
        'accounts' => AccountsImporter::class,
        'opening_balances' => OpeningBalancesImporter::class,
        'contacts' => ContactsImporter::class,
        'subscriptions' => SubscriptionsImporter::class,
        'quotations' => QuotationsImporter::class,
        'invoices' => InvoicesImporter::class,
        'receipts' => ReceiptsImporter::class,
        'timesheets' => TimesheetsImporter::class,
    ];

    /** @param  array<string, mixed>  $options */
    public static function run(Company $company, string $entity, string $path, bool $commit, ?User $actor = null, array $options = []): OdooImportRun
    {
        $class = self::ENTITIES[$entity] ?? throw new \InvalidArgumentException(
            "Unknown entity \"{$entity}\". Expected one of: ".implode(', ', array_keys(self::ENTITIES)).'.'
        );
        /** @var EntityImporter $importer */
        $importer = new $class;

        $rows = SpreadsheetReader::read($path);

        $run = OdooImportRun::create([
            'company_id' => $company->id,
            'entity' => $entity,
            'source_filename' => basename($path),
            'mode' => $commit ? OdooImportRun::MODE_COMMIT : OdooImportRun::MODE_DRY_RUN,
            'status' => OdooImportRun::STATUS_RUNNING,
            'rows_read' => count($rows),
            'started_by_user_id' => $actor?->id,
        ]);

        $ctx = new ImportContext($company, $run, $actor, $options);
        $counts = ['created' => 0, 'already_imported' => 0, 'skipped' => 0, 'failed' => 0];
        $report = [];

        DB::beginTransaction();
        try {
            foreach ($importer->records($rows) as $record) {
                $ctx->warnings = [];
                $ref = null;
                try {
                    $ref = $importer->odooRef($record, $ctx);
                    if ($ref === null) {
                        throw new RowFailed('No External ID (the `id` column) -- export from Odoo with "I want to update data (import-compatible export)" ticked.');
                    }
                    if ($ctx->mapped($entity, $ref) !== null) {
                        $outcome = 'already_imported';
                        $message = 'Imported by an earlier run; left as it is.';
                    } else {
                        $model = DB::transaction(function () use ($importer, $record, $ctx, $entity, $ref) {
                            $model = $importer->import($record, $ctx);
                            $ctx->remember($entity, $ref, $importer->targetType($model), $model);

                            return $model;
                        });
                        $outcome = 'created';
                        $message = $importer->describe($model);
                    }
                } catch (RowSkipped $e) {
                    $outcome = 'skipped';
                    $message = $e->getMessage();
                } catch (RowFailed $e) {
                    $outcome = 'failed';
                    $message = $e->getMessage();
                } catch (QueryException $e) {
                    $outcome = 'failed';
                    $message = 'Database refused the row: '.strtok($e->getPrevious()?->getMessage() ?? $e->getMessage(), "\n");
                }

                $counts[$outcome]++;
                $report[] = array_filter([
                    'row' => $record->rowNumber(),
                    'odoo_ref' => $ref,
                    'outcome' => $outcome,
                    'message' => $message,
                    'warnings' => $ctx->warnings ?: null,
                ], fn ($v) => $v !== null);
            }
        } catch (\Throwable $e) {
            // Anything unexpected: write nothing, and don't leave the
            // run looking as if it were still going.
            DB::rollBack();
            $run->fill(['status' => OdooImportRun::STATUS_FAILED, 'report' => $report, 'finished_at' => Carbon::now()])->save();

            throw $e;
        }

        if ($commit && $counts['failed'] === 0) {
            DB::commit();
            $status = OdooImportRun::STATUS_SUCCEEDED;
        } else {
            DB::rollBack();
            $status = $commit ? OdooImportRun::STATUS_FAILED : OdooImportRun::STATUS_DRY_RUN;
        }

        $run->fill([
            'status' => $status,
            'rows_created' => $counts['created'],
            'rows_already_imported' => $counts['already_imported'],
            'rows_skipped' => $counts['skipped'],
            'rows_failed' => $counts['failed'],
            'report' => $report,
            'finished_at' => Carbon::now(),
        ])->save();

        $verb = match ($status) {
            OdooImportRun::STATUS_SUCCEEDED => 'imported',
            OdooImportRun::STATUS_DRY_RUN => 'dry run: would import',
            default => 'refused (nothing written): would import',
        };
        Audit::record(
            entityType: 'odoo_import_run',
            entityId: $run->id,
            action: "odoo_import_{$status}",
            actorUserId: $actor?->id,
            actorName: $actor === null ? 'odoo:import (command line)' : null,
            companyId: $company->id,
            details: sprintf(
                'Odoo %s from %s -- %s %d, %d already imported, %d skipped, %d failed',
                $entity, $run->source_filename, $verb, $counts['created'], $counts['already_imported'], $counts['skipped'], $counts['failed'],
            ),
        );

        return $run;
    }
}
