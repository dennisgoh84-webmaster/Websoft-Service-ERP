<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Every model in the Python backend uses a UUID primary key
 * (`UUID(as_uuid=True), primary_key=True, default=uuid.uuid4`). This
 * trait mirrors that: the id is generated in the application layer on
 * create, not by a database default, so it doesn't depend on a
 * Postgres extension (pgcrypto/uuid-ossp) being installed.
 */
trait HasUuidPrimaryKey
{
    public static function bootHasUuidPrimaryKey(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function initializeHasUuidPrimaryKey(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }
}
