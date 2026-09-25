<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\GroupModuleAuthority;
use App\Models\MigrationBatch;
use App\Models\MigrationMapping;
use App\Models\MigrationRecordMap;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\DataMigration\MigrationBatches;
use App\Services\DataMigration\MigrationCatalog;
use App\Services\DataMigration\MigrationMappings;
use App\Services\DataMigration\MigrationRollback;
use App\Services\Exports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Maintenance -> Data Migration (docs/data-migration.md): bringing the
 * old ODOO and ZSOFT data in, module by module, into a chosen Internal
 * Company.
 *
 * Gated on `data_migration` (decided 2026-09-25): VIEW sees the
 * dashboard, modules, field mappings and the batch log; FULL uploads,
 * maps, signs off, dry runs, imports and rolls back. Every change is
 * written to Event Logs.
 */
class DataMigrationController extends Controller
{
    private const MODULE = 'data_migration';

    private const EXPORT_CAP = 5000;

    /** Dashboard + Migration Modules: every module with its progress. */
    public function overview(Request $request)
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::VIEW);

        return response()->json($this->overviewData($company));
    }

    public function modulesCsv(Request $request)
    {
        [$headers, $rows] = $this->modulesTable($request);

        return $this->csv($headers, $rows, 'migration-modules.csv');
    }

    public function modulesExcel(Request $request)
    {
        [$headers, $rows] = $this->modulesTable($request);

        return $this->excel($headers, $rows, 'migration-modules.xlsx', 'Modules');
    }

    private function modulesTable(Request $request): array
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::VIEW);
        $data = $this->overviewData($company);
        $statuses = ['not_started' => 'Not started', 'uploaded' => 'Uploaded', 'checking' => 'Dry run in progress', 'has_errors' => 'Has errors',
            'ready_to_import' => 'Ready to import', 'importing' => 'Importing', 'partly_imported' => 'Partly imported', 'complete' => 'Complete'];
        $rows = [];
        foreach ($data['modules'] as $m) {
            if (($source = $request->query('source')) && $m['source'] !== $source) {
                continue;
            }
            $rows[] = [
                $m['order'], $m['source_label'], $m['their_name'], $m['label'], 'No', $m['imported'], $m['total'],
                $m['failed'], $statuses[$m['status']] ?? $m['status'],
                $m['field_gap']['signed_off'] ? 'Signed off' : ($m['field_gap']['columns'] === 0 ? 'No file yet' : "{$m['field_gap']['undecided']} undecided, {$m['field_gap']['gaps']} gaps"),
                $m['last_activity_at'] ? Carbon::parse($m['last_activity_at'])->timezone('Asia/Singapore')->format('d/m/Y H:i') : '',
            ];
        }
        Audit::recordReportGenerated($user->id, 'data_migration_modules', "Data Migration modules export ({$company->name}) -- ".count($rows).' rows');

        return [['#', 'Source', 'Their module', 'Module here', 'Posts to GL', 'Imported', 'Rows in latest file', 'Rows with errors', 'Status', 'Field Gap', 'Last activity'], $rows];
    }

    private function overviewData(Company $company): array
    {

        $batches = MigrationBatch::where('company_id', $company->id)
            ->orderByDesc('started_at')->get()
            ->groupBy(fn (MigrationBatch $b) => "{$b->source}|{$b->entity}");
        $imported = MigrationRecordMap::live()->where('company_id', $company->id)
            ->selectRaw('source, entity, count(*) as n')->groupBy('source', 'entity')->get()
            ->mapWithKeys(fn ($r) => ["{$r->source}|{$r->entity}" => (int) $r->n]);
        $mappings = MigrationMapping::where('company_id', $company->id)->get()
            ->keyBy(fn (MigrationMapping $m) => "{$m->source}|{$m->entity}");

        $modules = [];
        foreach (MigrationCatalog::MODULES as $i => [$source, $entity, $theirs]) {
            $key = "{$source}|{$entity}";
            $mine = $batches->get($key, collect());
            $latest = $mine->first(fn (MigrationBatch $b) => $b->status !== MigrationBatch::STATUS_ROLLED_BACK);
            $lastImport = $mine->first(fn (MigrationBatch $b) => $b->status === MigrationBatch::STATUS_SUCCEEDED);
            $mapping = $mappings->get($key);
            $gap = $mapping ? MigrationMappings::status($mapping) : null;
            $count = $imported->get($key, 0);
            $total = max((int) ($latest?->rows_read ?? 0), $count);
            $running = $latest?->status === MigrationBatch::STATUS_RUNNING;

            $modules[] = [
                'order' => $i + 1,
                'source' => $source,
                'source_label' => MigrationCatalog::SOURCES[$source],
                'entity' => $entity,
                'their_name' => $theirs,
                'label' => MigrationCatalog::importer($entity)->label(),
                'posts_to_gl' => false,
                'total' => $total,
                'imported' => $count,
                'failed' => $latest && in_array($latest->status, [MigrationBatch::STATUS_DRY_RUN, MigrationBatch::STATUS_FAILED], true)
                    ? $latest->rows_failed + $latest->rows_needs_decision : 0,
                'in_progress' => $running && $latest->mode === MigrationBatch::MODE_COMMIT ? $latest->progress_done : 0,
                'status' => $this->moduleStatus($latest, $count, $total),
                'last_activity_at' => $mine->first()?->finished_at?->toIso8601String() ?? $mine->first()?->started_at?->toIso8601String(),
                'latest_batch_id' => $latest?->id,
                'latest_batch_number' => $latest?->batch_number,
                'last_import_batch_id' => $lastImport?->id,
                'last_import_batch_number' => $lastImport?->batch_number,
                'field_gap' => [
                    'columns' => $gap ? count($gap['columns']) : 0,
                    'undecided' => $gap['undecided'] ?? 0,
                    'gaps' => $gap['gaps'] ?? 0,
                    'signed_off' => $gap['signed_off'] ?? false,
                    'signed_off_at' => $mapping?->signed_off_at?->toIso8601String(),
                ],
            ];
        }

        $total = array_sum(array_column($modules, 'total'));
        $done = array_sum(array_column($modules, 'imported')) + array_sum(array_column($modules, 'in_progress'));

        return [
            'company' => ['id' => $company->id, 'code' => $company->code, 'name' => $company->name],
            'modules' => $modules,
            'totals' => [
                'modules' => count($modules),
                'complete' => count(array_filter($modules, fn ($m) => $m['status'] === 'complete')),
                'importing' => count(array_filter($modules, fn ($m) => $m['status'] === 'importing')),
                'rows_with_errors' => array_sum(array_column($modules, 'failed')),
                'records_total' => $total,
                'records_done' => $done,
                'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            ],
            'as_at' => Carbon::now()->toIso8601String(),
        ];
    }

    private function moduleStatus(?MigrationBatch $latest, int $imported, int $total): string
    {
        return match (true) {
            $latest === null => $imported > 0 ? 'complete' : 'not_started',
            $latest->status === MigrationBatch::STATUS_RUNNING => $latest->mode === MigrationBatch::MODE_COMMIT ? 'importing' : 'checking',
            $latest->status === MigrationBatch::STATUS_SUCCEEDED => $imported >= $total ? 'complete' : 'partly_imported',
            in_array($latest->status, [MigrationBatch::STATUS_DRY_RUN, MigrationBatch::STATUS_FAILED], true)
                && ($latest->rows_failed + $latest->rows_needs_decision > 0 || $latest->error_message) => 'has_errors',
            $latest->status === MigrationBatch::STATUS_DRY_RUN => 'ready_to_import',
            default => 'uploaded',
        };
    }

    /** The fields a column can be mapped to, and the module's saved mapping + Field Gap state. */
    public function mapping(Request $request, string $source, string $entity)
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::VIEW);
        $this->requireModule($source, $entity);

        return response()->json($this->mappingOut(MigrationMappings::get($company->id, $source, $entity)));
    }

    public function updateMapping(Request $request, string $source, string $entity)
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::FULL);
        $this->requireModule($source, $entity);
        $data = $request->validate(['mapping' => 'required|array']);

        try {
            $mapping = MigrationMappings::update(MigrationMappings::get($company->id, $source, $entity), $data['mapping'], $user);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->mappingOut($mapping));
    }

    public function signOff(Request $request, string $source, string $entity)
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::FULL);
        $this->requireModule($source, $entity);

        try {
            $mapping = MigrationMappings::signOff(MigrationMappings::get($company->id, $source, $entity), $user);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->mappingOut($mapping));
    }

    public function fieldGapCsv(Request $request, string $source, string $entity)
    {
        [$headers, $rows, $name] = $this->fieldGapTable($request, $source, $entity);

        return $this->csv($headers, $rows, "{$name}.csv");
    }

    public function fieldGapExcel(Request $request, string $source, string $entity)
    {
        [$headers, $rows, $name] = $this->fieldGapTable($request, $source, $entity);

        return $this->excel($headers, $rows, "{$name}.xlsx", 'Field Gap');
    }

    private function fieldGapTable(Request $request, string $source, string $entity): array
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::VIEW);
        $this->requireModule($source, $entity);
        $status = MigrationMappings::status(MigrationMappings::get($company->id, $source, $entity));
        $states = ['mapped' => 'Mapped', 'left_out' => 'Left out', 'field_gap' => 'Field Gap: needs a new field', 'undecided' => 'Undecided'];
        $rows = array_map(fn ($c) => [$c['header'], $states[$c['state']], $c['field_label'] ?? ''], $status['columns']);
        Audit::recordReportGenerated($user->id, 'data_migration_field_gap', strtoupper($source)." {$entity} Field Gap export -- ".count($rows).' columns');

        return [[strtoupper($source).' column', 'Decision', 'Websoft field'], $rows, "field-gap-{$source}-{$entity}"];
    }

    /** Upload a file: step 1 of the import. */
    public function upload(Request $request)
    {
        $user = Authenticate::user($request);
        $company = $this->company($request, $user, GroupModuleAuthority::FULL);
        $data = $request->validate([
            'source' => 'required|in:odoo,zsoft',
            'entity' => 'required|string',
            'file' => 'required|file|max:20480',
        ]);
        $this->requireModule($data['source'], $data['entity']);
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw new ApiException(422, 'Upload an Excel (.xlsx) or CSV file.');
        }

        $batch = MigrationBatches::upload($company, $data['source'], $data['entity'], $request->file('file'), $user);

        return response()->json($this->batchOut($batch, true), 201);
    }

    public function batches(Request $request)
    {
        $user = Authenticate::user($request);
        $companies = $this->companies($request, $user);

        return response()->json($this->batchQuery($request, $companies)->limit(500)->get()->map(fn ($b) => $this->batchOut($b)));
    }

    public function batchesCsv(Request $request)
    {
        [$headers, $rows] = $this->batchTable($request);

        return $this->csv($headers, $rows, 'migration-batches.csv');
    }

    public function batchesExcel(Request $request)
    {
        [$headers, $rows] = $this->batchTable($request);

        return $this->excel($headers, $rows, 'migration-batches.xlsx', 'Batches');
    }

    private function batchTable(Request $request): array
    {
        $user = Authenticate::user($request);
        $companies = $this->companies($request, $user);
        $rows = $this->batchQuery($request, $companies)->limit(self::EXPORT_CAP)->get()->map(fn (MigrationBatch $b) => [
            $b->batch_number, $b->company?->name, $this->sgt($b->started_at), strtoupper($b->source),
            MigrationCatalog::importer($b->entity)->label(), $b->source_filename, $b->rows_read,
            $b->rows_created, $b->rows_linked, $b->rows_failed, $b->rows_needs_decision,
            $this->statusLabel($b), $b->startedBy?->full_name, $this->sgt($b->rolled_back_at), $b->rollback_reason,
        ])->all();
        Audit::recordReportGenerated($user->id, 'data_migration_batches', 'Data Migration batch log export -- '.count($rows).' rows');

        return [['Batch', 'Internal Company', 'Date / time', 'Source', 'Module', 'File', 'Rows', 'New', 'Linked', 'Failed', 'To decide', 'Status', 'By', 'Rolled back', 'Roll back reason'], $rows];
    }

    /** @param  array<int, string>  $companyIds */
    private function batchQuery(Request $request, array $companyIds): Builder
    {
        $q = MigrationBatch::with(['startedBy', 'rolledBackBy', 'company'])->whereIn('company_id', $companyIds);
        foreach (['source', 'entity', 'status'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($from = $request->query('date_from')) {
            $q->where('started_at', '>=', Carbon::parse($from, 'Asia/Singapore')->startOfDay());
        }
        if ($to = $request->query('date_to')) {
            $q->where('started_at', '<=', Carbon::parse($to, 'Asia/Singapore')->endOfDay());
        }

        return $q->orderByDesc('started_at');
    }

    public function show(Request $request, string $batchId)
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::VIEW);

        return response()->json($this->batchOut($batch, true));
    }

    /** Polled while a dry run / import runs. */
    public function progress(Request $request, string $batchId)
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::VIEW);

        return response()->json([
            'id' => $batch->id,
            'status' => $batch->status,
            'mode' => $batch->mode,
            'progress_done' => $batch->progress_done,
            'progress_total' => $batch->progress_total,
        ]);
    }

    public function decisions(Request $request, string $batchId)
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::FULL);
        $data = $request->validate(['decisions' => 'required|array']);

        return $this->guarded(fn () => MigrationBatches::saveDecisions($batch, $data['decisions'], $user));
    }

    public function dryRun(Request $request, string $batchId)
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::FULL);

        return $this->guarded(fn () => MigrationBatches::startDryRun($batch, $user));
    }

    public function import(Request $request, string $batchId)
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::FULL);

        return $this->guarded(fn () => MigrationBatches::startImport($batch, $user));
    }

    public function rollback(Request $request, string $batchId)
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::FULL);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);

        try {
            $result = MigrationRollback::run($batch, $user, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($result + ['batch' => $this->batchOut($batch->fresh(), true)], $result['rolled_back'] ? 200 : 409);
    }

    public function problemsCsv(Request $request, string $batchId)
    {
        [$headers, $rows, $name] = $this->problemTable($request, $batchId);

        return $this->csv($headers, $rows, "{$name}.csv");
    }

    public function problemsExcel(Request $request, string $batchId)
    {
        [$headers, $rows, $name] = $this->problemTable($request, $batchId);

        return $this->excel($headers, $rows, "{$name}.xlsx", 'Problems');
    }

    private function problemTable(Request $request, string $batchId): array
    {
        $user = Authenticate::user($request);
        $batch = $this->batch($user, $batchId, GroupModuleAuthority::VIEW);
        $labels = ['failed' => 'Error', 'needs_decision' => 'Possible duplicate', 'skipped' => 'Skipped', 'linked' => 'Linked to existing', 'created' => 'New', 'already_imported' => 'Already imported'];
        $rows = [];
        foreach ($batch->report ?? [] as $r) {
            if ($r['outcome'] === 'created' && empty($r['warnings'])) {
                continue;
            }
            $rows[] = [$r['row'], $r['source_ref'] ?? '', $labels[$r['outcome']] ?? $r['outcome'], $r['message'] ?? '', implode(' | ', $r['warnings'] ?? [])];
        }
        Audit::recordReportGenerated($user->id, 'data_migration_problems', "{$batch->batch_number} problem rows export -- ".count($rows).' rows');

        return [['Row', 'Source ID', 'Outcome', 'Problem', 'Notes'], $rows, "{$batch->batch_number}-problems"];
    }

    // ---- helpers -------------------------------------------------------

    private function guarded(callable $fn)
    {
        try {
            $batch = $fn();
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return response()->json($this->batchOut($batch->fresh(), true));
    }

    private function company(Request $request, User $user, string $level): Company
    {
        $companyId = (string) ($request->input('company_id') ?: $request->query('company_id') ?: $user->company_id);
        if (! in_array($companyId, CompanyController::accessibleCompanyIds($user), true)) {
            throw new ApiException(403, 'You do not have access to that Internal Company.');
        }
        Authority::requireModuleAccessIn($user, $companyId, self::MODULE, $level);

        return Company::findOrFail($companyId);
    }

    /**
     * Internal Companies the batch log may show: those asked for (all
     * accessible ones when none are), each needing VIEW.
     *
     * @return array<int, string>
     */
    private function companies(Request $request, User $user): array
    {
        $accessible = CompanyController::accessibleCompanyIds($user);
        $asked = array_filter(explode(',', (string) $request->query('company_ids', '')));
        $ids = $asked ? array_values(array_intersect($asked, $accessible)) : [$user->company_id];
        if ($ids === []) {
            throw new ApiException(403, 'You do not have access to those Internal Companies.');
        }
        foreach ($ids as $id) {
            Authority::requireModuleAccessIn($user, $id, self::MODULE, GroupModuleAuthority::VIEW);
        }

        return $ids;
    }

    private function batch(User $user, string $batchId, string $level): MigrationBatch
    {
        $batch = MigrationBatch::with(['startedBy', 'rolledBackBy', 'company'])->find($batchId);
        if ($batch === null || ! in_array($batch->company_id, CompanyController::accessibleCompanyIds($user), true)) {
            throw new ApiException(404, 'Batch not found.');
        }
        Authority::requireModuleAccessIn($user, $batch->company_id, self::MODULE, $level);

        return $batch;
    }

    private function requireModule(string $source, string $entity): void
    {
        if (MigrationCatalog::module($source, $entity) === null) {
            throw new ApiException(404, 'That module is not part of the migration.');
        }
    }

    private function mappingOut(MigrationMapping $mapping): array
    {
        $importer = MigrationCatalog::importer($mapping->entity);
        $fields = [];
        foreach ($importer->fields() as $key => $f) {
            $fields[] = ['key' => $key, 'label' => $f['label'], 'required' => (bool) ($f['required'] ?? false), 'hint' => $f['hint'] ?? null];
        }

        return MigrationMappings::status($mapping) + [
            'source' => $mapping->source,
            'entity' => $mapping->entity,
            'label' => $importer->label(),
            'their_name' => MigrationCatalog::module($mapping->source, $mapping->entity)['their_name'],
            'fields' => $fields,
            'signed_off_at' => $mapping->signed_off_at?->toIso8601String(),
            'signed_off_by' => $mapping->signed_off_by_user_id ? User::find($mapping->signed_off_by_user_id)?->full_name : null,
        ];
    }

    private function batchOut(MigrationBatch $b, bool $detail = false): array
    {
        $out = [
            'id' => $b->id,
            'batch_number' => $b->batch_number,
            'company_id' => $b->company_id,
            'company_name' => $b->company?->name,
            'source' => $b->source,
            'source_label' => MigrationCatalog::SOURCES[$b->source] ?? strtoupper($b->source),
            'entity' => $b->entity,
            'module_label' => MigrationCatalog::importer($b->entity)->label(),
            'their_name' => MigrationCatalog::module($b->source, $b->entity)['their_name'] ?? null,
            'source_filename' => $b->source_filename,
            'status' => $b->status,
            'status_label' => $this->statusLabel($b),
            'mode' => $b->mode,
            'rows_read' => $b->rows_read,
            'rows_created' => $b->rows_created,
            'rows_linked' => $b->rows_linked,
            'rows_already_imported' => $b->rows_already_imported,
            'rows_skipped' => $b->rows_skipped,
            'rows_failed' => $b->rows_failed,
            'rows_needs_decision' => $b->rows_needs_decision,
            'progress_done' => $b->progress_done,
            'progress_total' => $b->progress_total,
            'error_message' => $b->error_message,
            'started_by' => $b->startedBy?->full_name,
            'started_at' => $b->started_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'imported_at' => $b->imported_at?->toIso8601String(),
            'rolled_back_at' => $b->rolled_back_at?->toIso8601String(),
            'rolled_back_by' => $b->rolledBackBy?->full_name,
            'rollback_reason' => $b->rollback_reason,
        ];
        if (! $detail) {
            return $out;
        }

        $report = $b->report ?? [];
        $problems = array_values(array_filter($report, fn ($r) => $r['outcome'] !== 'created' || ! empty($r['warnings'])));

        return $out + [
            'headers' => $b->headers ?? [],
            'sample_row' => $b->sample_row ?? (object) [],
            'mapping' => $b->mapping ?? [],
            'decisions' => $b->decisions ?? (object) [],
            'problems' => array_slice($problems, 0, 1000),
            'problems_total' => count($problems),
            'sample_created' => array_slice(array_values(array_filter($report, fn ($r) => $r['outcome'] === 'created')), 0, 20),
            'rollback_report' => $b->rollback_report ? array_diff_key($b->rollback_report, ['removed' => true]) : null,
        ];
    }

    private function statusLabel(MigrationBatch $b): string
    {
        return match ($b->status) {
            MigrationBatch::STATUS_UPLOADED => 'Uploaded',
            MigrationBatch::STATUS_RUNNING => $b->mode === MigrationBatch::MODE_COMMIT ? 'Importing' : 'Dry run in progress',
            MigrationBatch::STATUS_DRY_RUN => $b->rows_failed + $b->rows_needs_decision > 0 ? 'Dry run: has errors' : 'Dry run: ready to import',
            MigrationBatch::STATUS_FAILED => $b->mode === MigrationBatch::MODE_COMMIT ? 'Import refused' : 'Failed',
            MigrationBatch::STATUS_SUCCEEDED => 'Imported',
            MigrationBatch::STATUS_ROLLED_BACK => 'Rolled back',
            default => $b->status,
        };
    }

    /** DD/MM/YYYY HH:MM, Singapore time (Dennis's standing rule). */
    private function sgt(?Carbon $at): string
    {
        return $at ? $at->copy()->timezone('Asia/Singapore')->format('d/m/Y H:i') : '';
    }

    private function csv(array $headers, array $rows, string $filename)
    {
        return response(Exports::tableToCsv($headers, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    private function excel(array $headers, array $rows, string $filename, string $sheet)
    {
        return response(Exports::tableToExcel($headers, $rows, $sheet), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
