<?php

namespace App\Services\DataMigration;

use RuntimeException;

/**
 * A Company / Individual that looks like one already here on a weak
 * match (same name, no matching UEN or GST no.): the user decides
 * Link or Create new in the dry-run preview. Blocks the import until
 * decided.
 */
class NeedsDecision extends RuntimeException
{
    /** @param  array<int, array{id: string, name: string, detail: string}>  $candidates */
    public function __construct(string $message, public array $candidates)
    {
        parent::__construct($message);
    }
}
