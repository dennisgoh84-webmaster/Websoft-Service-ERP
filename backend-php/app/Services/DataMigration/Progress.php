<?php

namespace App\Services\DataMigration;

use Illuminate\Support\Facades\DB;

/**
 * Live progress for the Data Migration dashboard. The import itself
 * runs inside one transaction (all or nothing), where nothing it writes
 * is visible to anyone else until the end -- so progress goes through a
 * second connection of its own, committed as it happens.
 */
class Progress
{
    private const CONNECTION = 'migration_progress';

    /** @param  array<string, mixed>  $values */
    public static function update(string $batchId, array $values): void
    {
        if (config('database.connections.'.self::CONNECTION) === null) {
            config(['database.connections.'.self::CONNECTION => config('database.connections.'.config('database.default'))]);
        }
        try {
            DB::connection(self::CONNECTION)->table('migration_batches')->where('id', $batchId)->update($values);
        } catch (\Throwable) {
            // Progress is a convenience; never let it break an import.
        }
    }
}
