<?php

namespace App\Services\DataMigration;

use App\Models\Company;
use App\Models\MigrationBatch;
use App\Models\MigrationMapping;
use App\Models\User;
use App\Services\Audit;
use App\Services\Numbering;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * The batch lifecycle behind the Data Migration screens
 * (docs/data-migration.md): upload -> map fields -> dry run -> import
 * -> (roll back).
 *
 * A dry run or import of a large file can outlast a web request, so it
 * runs as a background `php artisan data-migration:run` process on the
 * server, publishing progress the dashboard polls. Under the test suite
 * (and wherever a background process cannot be started) it runs inline.
 */
class MigrationBatches
{
    /** Upload: store the file, read its headings, fold them into the saved mapping. */
    public static function upload(Company $company, string $source, string $entity, UploadedFile|string $file, User $actor): MigrationBatch
    {
        MigrationCatalog::requireModule($source, $entity);

        $batch = DB::transaction(function () use ($company, $source, $entity, $file, $actor) {
            $batch = MigrationBatch::create([
                'company_id' => $company->id,
                'source' => $source,
                'entity' => $entity,
                'batch_number' => Numbering::next($company->id, 'migration_batch'),
                'source_filename' => $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file),
                'status' => MigrationBatch::STATUS_UPLOADED,
                'started_by_user_id' => $actor->id,
            ]);
            $batch->stored_path = $file instanceof UploadedFile ? MigrationFiles::store($batch, $file) : MigrationFiles::storeLocal($batch, $file);
            $batch->save();

            return $batch;
        });

        try {
            $headers = SpreadsheetReader::headers(MigrationFiles::path($batch));
            $all = SpreadsheetReader::read(MigrationFiles::path($batch));
            $rows = count($all);
            $sample = [];
            foreach ($headers as $header) {
                $sample[$header] = $all[0]->values[SourceRow::normaliseHeader($header)] ?? '';
            }
        } catch (\Throwable $e) {
            $batch->fill(['status' => MigrationBatch::STATUS_FAILED, 'error_message' => 'Could not read the file: '.$e->getMessage()])->save();

            return $batch;
        }
        if ($headers === []) {
            $batch->fill(['status' => MigrationBatch::STATUS_FAILED, 'error_message' => 'The file is empty -- the first row must be the column headings.'])->save();

            return $batch;
        }

        $mapping = MigrationMappings::absorbHeaders(MigrationMappings::get($company->id, $source, $entity), $headers);
        $batch->fill(['headers' => $headers, 'sample_row' => $sample ?? [], 'rows_read' => $rows, 'progress_total' => $rows, 'mapping' => self::mappingFor($mapping, $headers)])->save();

        Audit::record(
            entityType: 'migration_batch', entityId: $batch->id, action: 'data_migration_uploaded',
            actorUserId: $actor->id,
            details: "{$batch->batch_number}: {$batch->source_filename} uploaded for ".strtoupper($source)." {$entity} into {$company->name} ({$rows} rows, ".count($headers).' columns)',
        );

        return $batch;
    }

    /** @param  array<string, string>  $decisions  source ref => "new" | "link:<id>" */
    public static function saveDecisions(MigrationBatch $batch, array $decisions, User $actor): MigrationBatch
    {
        self::requireIdle($batch);
        foreach ($decisions as $ref => $decision) {
            if ($decision !== 'new' && ! preg_match('/^link:[0-9a-f-]{36}$/', (string) $decision)) {
                throw new \InvalidArgumentException("Decision for {$ref} must be Link or Create new.");
            }
        }
        $batch->fill([
            'decisions' => array_merge($batch->decisions ?? [], $decisions),
            // A decision changes what the import would do: dry run again.
            'status' => $batch->status === MigrationBatch::STATUS_DRY_RUN ? MigrationBatch::STATUS_UPLOADED : $batch->status,
        ])->save();
        Audit::record(
            entityType: 'migration_batch', entityId: $batch->id, action: 'data_migration_duplicates_decided',
            actorUserId: $actor->id, details: "{$batch->batch_number}: ".count($decisions).' duplicate decision(s)',
            newValue: ['decisions' => $decisions],
        );

        return $batch;
    }

    public static function startDryRun(MigrationBatch $batch, User $actor): MigrationBatch
    {
        self::requireIdle($batch);
        if (! in_array($batch->status, [MigrationBatch::STATUS_UPLOADED, MigrationBatch::STATUS_DRY_RUN, MigrationBatch::STATUS_FAILED], true) || $batch->stored_path === null) {
            throw new \InvalidArgumentException('This batch cannot be dry run.');
        }
        $mapping = MigrationMappings::get($batch->company_id, $batch->source, $batch->entity);
        $batch->fill(['mapping' => self::mappingFor($mapping, $batch->headers ?? [])])->save();

        return self::start($batch, false, $actor);
    }

    /**
     * Import: only after a clean dry run with the mapping as it stands
     * now, and with the module's Field Gap list signed off.
     */
    public static function startImport(MigrationBatch $batch, User $actor): MigrationBatch
    {
        self::requireIdle($batch);
        $mapping = MigrationMappings::get($batch->company_id, $batch->source, $batch->entity);
        if ($mapping->signed_off_at === null) {
            throw new \InvalidArgumentException('Sign off this module\'s Field Gap list first.');
        }
        if ($batch->status !== MigrationBatch::STATUS_DRY_RUN) {
            throw new \InvalidArgumentException('Run a dry run first.');
        }
        if ($batch->rows_failed > 0 || $batch->rows_needs_decision > 0) {
            throw new \InvalidArgumentException('Fix the errors and decide the possible duplicates, then dry run again.');
        }
        if (self::mappingFor($mapping, $batch->headers ?? []) != ($batch->mapping ?? [])) {
            throw new \InvalidArgumentException('The field mapping changed after the dry run -- dry run again.');
        }

        return self::start($batch, true, $actor);
    }

    private static function start(MigrationBatch $batch, bool $commit, User $actor): MigrationBatch
    {
        $batch->fill([
            'mode' => $commit ? MigrationBatch::MODE_COMMIT : MigrationBatch::MODE_DRY_RUN,
            'status' => MigrationBatch::STATUS_RUNNING,
            'progress_done' => 0,
            'error_message' => null,
            'started_by_user_id' => $actor->id,
        ])->save();

        if (! self::runsInBackground()) {
            return MigrationEngine::run($batch, $commit, $actor);
        }

        $process = Process::fromShellCommandline(sprintf(
            'nohup %s %s data-migration:run %s %s %s > /dev/null 2>&1 &',
            escapeshellarg((string) config('websoft.php_cli', 'php')),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($batch->id),
            $commit ? 'commit' : 'dry-run',
            escapeshellarg($actor->id),
        ), base_path());
        try {
            $process->run();
        } catch (\Throwable) {
            return MigrationEngine::run($batch, $commit, $actor);
        }

        return $batch;
    }

    private static function runsInBackground(): bool
    {
        return ! app()->runningUnitTests() && (bool) config('websoft.migration_background', true);
    }

    private static function requireIdle(MigrationBatch $batch): void
    {
        if ($batch->status === MigrationBatch::STATUS_RUNNING) {
            throw new \InvalidArgumentException('This batch is running; wait for it to finish.');
        }
        if (in_array($batch->status, [MigrationBatch::STATUS_SUCCEEDED, MigrationBatch::STATUS_ROLLED_BACK], true)) {
            throw new \InvalidArgumentException('This batch has already been imported.');
        }
    }

    /**
     * The mapping as it applies to this file's columns.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string|null>
     */
    public static function mappingFor(MigrationMapping $mapping, array $headers): array
    {
        $all = $mapping->mapping ?? [];
        $out = [];
        foreach ($headers as $header) {
            $key = $all[$header] ?? null;
            $out[$header] = in_array($key, [MigrationMapping::SKIP, MigrationMapping::NEW_FIELD], true) ? null : $key;
        }

        return $out;
    }
}
