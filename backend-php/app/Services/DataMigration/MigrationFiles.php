<?php

namespace App\Services\DataMigration;

use App\Models\MigrationBatch;
use Illuminate\Http\UploadedFile;

/**
 * Where an uploaded migration file lives: under the same uploads
 * directory as every other stored file (a persistent volume on the
 * server), at <uploads_dir>/<company_id>/migration/<batch_id>/<file>.
 * Kept after import -- the batch record shows exactly which file
 * produced which records.
 */
class MigrationFiles
{
    public static function store(MigrationBatch $batch, UploadedFile $file): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?: 'upload';
        $relative = "{$batch->company_id}/migration/{$batch->id}/{$name}";
        $dir = dirname(self::root().'/'.$relative);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file->move($dir, $name);

        return $relative;
    }

    /** Copy a file already on disk (the tests' and a server-side upload's route). */
    public static function storeLocal(MigrationBatch $batch, string $path): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($path));
        $relative = "{$batch->company_id}/migration/{$batch->id}/{$name}";
        $dir = dirname(self::root().'/'.$relative);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        copy($path, self::root().'/'.$relative);

        return $relative;
    }

    public static function path(MigrationBatch $batch): string
    {
        return self::root().'/'.$batch->stored_path;
    }

    private static function root(): string
    {
        return rtrim((string) config('websoft.uploads_dir'), '/');
    }
}
