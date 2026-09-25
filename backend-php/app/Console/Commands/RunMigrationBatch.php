<?php

namespace App\Console\Commands;

use App\Models\MigrationBatch;
use App\Models\User;
use App\Services\DataMigration\MigrationEngine;
use Illuminate\Console\Command;

/**
 * Runs one Data Migration batch's dry run or import in the background
 * (started by App\Services\DataMigration\MigrationBatches, not by hand)
 * -- see docs/data-migration.md.
 */
class RunMigrationBatch extends Command
{
    protected $signature = 'data-migration:run {batch : Batch id} {mode : dry-run | commit} {user? : Staff user id who started it}';

    protected $description = 'Run a Data Migration batch (started from Maintenance -> Data Migration)';

    public function handle(): int
    {
        $batch = MigrationBatch::find($this->argument('batch'));
        if ($batch === null) {
            $this->error('No such batch.');

            return self::FAILURE;
        }
        $actor = $this->argument('user') ? User::find($this->argument('user')) : null;
        set_time_limit(0);

        $batch = MigrationEngine::run($batch, $this->argument('mode') === 'commit', $actor);
        $this->info("{$batch->batch_number}: {$batch->status}");

        return self::SUCCESS;
    }
}
