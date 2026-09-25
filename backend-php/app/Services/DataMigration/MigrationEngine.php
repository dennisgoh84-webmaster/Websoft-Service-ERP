<?php

namespace App\Services\DataMigration;

use App\Models\MigrationBatch;
use App\Models\MigrationRecordMap;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Data Migration engine (docs/data-migration.md). One run imports
 * one uploaded batch for one module:
 *
 * - The file is re-keyed through the batch's field mapping, so the
 *   importers see this system's field keys whatever the old system
 *   called its columns.
 * - The whole batch runs in one transaction, each record in its own
 *   savepoint, so one bad row is reported without taking the rest of
 *   the report down with it.
 * - A dry run does all of it -- every lookup, every constraint -- and
 *   then rolls back, so what it reports is exactly what the import
 *   would do.
 * - An import is all or nothing: one failed row, or one possible
 *   duplicate nobody has decided, rolls the whole batch back. Skipped
 *   rows (drafts, cancellations, credit notes) are reported and do not
 *   block it.
 * - A record already imported from the same source is left alone --
 *   never overwritten, since it may have been edited here since -- so
 *   a corrected file can be uploaded again safely.
 *
 * Every run leaves its report on the batch and an Event Log entry.
 */
class MigrationEngine
{
    /** How often (in records) live progress is published. */
    private const PROGRESS_EVERY = 25;

    public static function run(MigrationBatch $batch, bool $commit, ?User $actor = null): MigrationBatch
    {
        $importer = MigrationCatalog::importer($batch->entity);
        $company = $batch->company;

        $batch->fill([
            'mode' => $commit ? MigrationBatch::MODE_COMMIT : MigrationBatch::MODE_DRY_RUN,
            'status' => MigrationBatch::STATUS_RUNNING,
            'progress_done' => 0,
            'error_message' => null,
            'started_by_user_id' => $actor?->id ?? $batch->started_by_user_id,
            'started_at' => Carbon::now(),
            'finished_at' => null,
        ])->save();

        try {
            $records = $importer->records(self::mappedRows($batch, $importer));
        } catch (\InvalidArgumentException|RowFailed $e) {
            return self::finish($batch, MigrationBatch::STATUS_FAILED, [], [], $actor, $e->getMessage());
        }
        $batch->forceFill(['progress_total' => count($records)])->save();

        $ctx = new ImportContext($company, $batch, $actor, $batch->decisions ?? []);
        $counts = ['created' => 0, 'linked' => 0, 'already_imported' => 0, 'skipped' => 0, 'failed' => 0, 'needs_decision' => 0];
        $report = [];
        $entity = $importer->entity();

        DB::beginTransaction();
        try {
            foreach ($records as $i => $record) {
                $ctx->warnings = [];
                $ctx->linked = false;
                $ref = null;
                $candidates = null;
                try {
                    $ref = $importer->sourceRef($record, $ctx);
                    if ($ref === null) {
                        throw new RowFailed('No Source ID or document number on this row.');
                    }
                    if ($ctx->mapped($entity, $ref) !== null) {
                        $outcome = 'already_imported';
                        $message = 'Imported by an earlier batch; left as it is.';
                    } else {
                        $model = DB::transaction(function () use ($importer, $record, $ctx, $entity, $ref) {
                            $model = $importer->import($record, $ctx);
                            $ctx->remember($entity, $ref, $importer->targetType($model), $model,
                                $ctx->linked ? MigrationRecordMap::ACTION_LINKED : MigrationRecordMap::ACTION_CREATED);

                            return $model;
                        });
                        $outcome = $ctx->linked ? 'linked' : 'created';
                        $message = $importer->describe($model);
                    }
                } catch (RowSkipped $e) {
                    $outcome = 'skipped';
                    $message = $e->getMessage();
                } catch (NeedsDecision $e) {
                    $outcome = 'needs_decision';
                    $message = $e->getMessage();
                    $candidates = $e->candidates;
                } catch (RowFailed $e) {
                    $outcome = 'failed';
                    $message = $e->getMessage();
                } catch (QueryException $e) {
                    $outcome = 'failed';
                    $message = 'Refused by the database: '.strtok($e->getPrevious()?->getMessage() ?? $e->getMessage(), "\n");
                }

                $counts[$outcome]++;
                $report[] = array_filter([
                    'row' => $record->rowNumber(),
                    'source_ref' => $ref,
                    'outcome' => $outcome,
                    'message' => $message,
                    'warnings' => $ctx->warnings ?: null,
                    'candidates' => $candidates,
                ], fn ($v) => $v !== null);

                if (($i + 1) % self::PROGRESS_EVERY === 0) {
                    Progress::update($batch->id, ['progress_done' => $i + 1]);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            self::finish($batch, MigrationBatch::STATUS_FAILED, $counts, $report, $actor, 'The run stopped unexpectedly; nothing was written. '.$e->getMessage());

            throw $e;
        }

        $clean = $counts['failed'] === 0 && $counts['needs_decision'] === 0;
        if ($commit && $clean) {
            DB::commit();
            $status = MigrationBatch::STATUS_SUCCEEDED;
        } else {
            DB::rollBack();
            $status = $commit ? MigrationBatch::STATUS_FAILED : MigrationBatch::STATUS_DRY_RUN;
        }

        return self::finish($batch, $status, $counts, $report, $actor);
    }

    /**
     * The file's rows re-keyed by field key through the batch mapping.
     * Refuses to start when a required field has no column.
     *
     * @return array<int, SourceRow>
     */
    private static function mappedRows(MigrationBatch $batch, EntityImporter $importer): array
    {
        $mapping = $batch->mapping ?? [];
        $fields = $importer->fields();
        $missing = [];
        foreach ($fields as $key => $field) {
            if (($field['required'] ?? false) && ! in_array($key, $mapping, true)) {
                $missing[] = $field['label'];
            }
        }
        if ($missing !== []) {
            throw new RowFailed('Map a column to '.implode(', ', $missing).' first.');
        }

        $byHeader = [];
        foreach ($mapping as $header => $key) {
            if (is_string($key) && isset($fields[$key])) {
                $byHeader[SourceRow::normaliseHeader((string) $header)] = $key;
            }
        }

        $rows = [];
        foreach (SpreadsheetReader::read(MigrationFiles::path($batch)) as $row) {
            $values = [];
            foreach ($row->values as $header => $value) {
                $key = $byHeader[$header] ?? null;
                if ($key !== null && (! isset($values[$key]) || trim($values[$key]) === '')) {
                    $values[$key] = $value;
                }
            }
            $rows[] = new SourceRow($row->number, $values);
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, array<string, mixed>>  $report
     */
    private static function finish(MigrationBatch $batch, string $status, array $counts, array $report, ?User $actor, ?string $error = null): MigrationBatch
    {
        $batch->fill([
            'status' => $status,
            'rows_created' => $counts['created'] ?? 0,
            'rows_linked' => $counts['linked'] ?? 0,
            'rows_already_imported' => $counts['already_imported'] ?? 0,
            'rows_skipped' => $counts['skipped'] ?? 0,
            'rows_failed' => $counts['failed'] ?? 0,
            'rows_needs_decision' => $counts['needs_decision'] ?? 0,
            'progress_done' => count($report),
            'report' => $report,
            'error_message' => $error,
            'finished_at' => Carbon::now(),
            'imported_at' => $status === MigrationBatch::STATUS_SUCCEEDED ? Carbon::now() : $batch->imported_at,
        ])->save();

        $verb = match ($status) {
            MigrationBatch::STATUS_SUCCEEDED => 'imported',
            MigrationBatch::STATUS_DRY_RUN => 'dry run, would import',
            default => 'refused, nothing written; would import',
        };
        Audit::record(
            entityType: 'migration_batch',
            entityId: $batch->id,
            action: 'data_migration_'.($status === MigrationBatch::STATUS_DRY_RUN ? 'dry_run' : ($status === MigrationBatch::STATUS_SUCCEEDED ? 'imported' : 'refused')),
            actorUserId: $actor?->id,
            actorName: $actor === null ? 'Data Migration (background)' : null,
            companyId: $batch->company_id,
            details: $error ?? sprintf(
                '%s %s %s (%s): %s %d new, %d linked to existing, %d already imported, %d skipped, %d failed, %d to decide',
                $batch->batch_number, strtoupper($batch->source), $batch->entity, $batch->source_filename, $verb,
                $batch->rows_created, $batch->rows_linked, $batch->rows_already_imported, $batch->rows_skipped,
                $batch->rows_failed, $batch->rows_needs_decision,
            ),
        );

        return $batch;
    }
}
