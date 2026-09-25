<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\OdooImportRun;
use App\Models\User;
use App\Services\OdooMigration\OdooImporter;
use Illuminate\Console\Command;

/**
 * Import one Odoo export file (docs/odoo-migration.md). A DRY RUN
 * unless --commit is given: the dry run does every lookup and write
 * and then rolls back, so its report is exactly what a commit would
 * do. A commit writes nothing at all if any row fails.
 */
class OdooImport extends Command
{
    protected $signature = 'odoo:import
        {entity : accounts | opening_balances | contacts | subscriptions | quotations | invoices | receipts | timesheets}
        {file : The Odoo export (.csv or .xlsx)}
        {--company= : Company code (Company Setup) to import into}
        {--commit : Write the records; without it this is a dry run}
        {--user= : Staff user (email or username) to record as having run the import}
        {--as-at= : Cut-over date for opening_balances (YYYY-MM-DD)}
        {--show=all : Which rows to list: all | problems | none}';

    protected $description = 'Import an Odoo export into Websoft (dry run unless --commit)';

    public function handle(): int
    {
        $company = Company::where('code', (string) $this->option('company'))->first();
        if ($company === null) {
            $this->error('Give the target company with --company=<code> (the code shown on Company Setup).');

            return self::FAILURE;
        }

        $actor = null;
        if ($this->option('user') !== null) {
            $actor = User::where('email', $this->option('user'))->orWhere('username', $this->option('user'))->first();
            if ($actor === null) {
                $this->error("No staff user {$this->option('user')}.");

                return self::FAILURE;
            }
        }

        try {
            $run = OdooImporter::run(
                company: $company,
                entity: (string) $this->argument('entity'),
                path: (string) $this->argument('file'),
                commit: (bool) $this->option('commit'),
                actor: $actor,
                options: ['as_at' => $this->option('as-at')],
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $show = (string) $this->option('show');
        $rows = collect($run->report ?? [])
            ->filter(fn ($r) => $show === 'all' || ($show === 'problems' && ($r['outcome'] !== 'created' || ! empty($r['warnings']))))
            ->map(fn ($r) => [$r['row'], $r['odoo_ref'] ?? '', $r['outcome'], $r['message'].(empty($r['warnings']) ? '' : "\n  ! ".implode("\n  ! ", $r['warnings']))]);
        if ($show !== 'none' && $rows->isNotEmpty()) {
            $this->table(['Row', 'Odoo ID', 'Outcome', 'Detail'], $rows->all());
        }

        $summary = sprintf(
            '%s %s: %d read, %d %s, %d already imported, %d skipped, %d failed. Run %s.',
            $run->source_filename, $run->entity, $run->rows_read, $run->rows_created,
            $run->status === OdooImportRun::STATUS_SUCCEEDED ? 'created' : 'would be created',
            $run->rows_already_imported, $run->rows_skipped, $run->rows_failed, $run->id,
        );

        return match ($run->status) {
            OdooImportRun::STATUS_SUCCEEDED => tap(self::SUCCESS, fn () => $this->info("Imported. {$summary}")),
            OdooImportRun::STATUS_DRY_RUN => tap($run->rows_failed === 0 ? self::SUCCESS : self::FAILURE, fn () => $this->warn(
                "DRY RUN -- nothing written. {$summary}".($run->rows_failed === 0 ? ' Re-run with --commit to import.' : ' Fix the failed rows first.')
            )),
            default => tap(self::FAILURE, fn () => $this->error("NOTHING WRITTEN -- fix the failed rows and re-run. {$summary}")),
        };
    }
}
