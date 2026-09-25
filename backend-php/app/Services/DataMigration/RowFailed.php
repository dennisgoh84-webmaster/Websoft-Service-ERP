<?php

namespace App\Services\DataMigration;

use RuntimeException;

/** A row that cannot be imported as it stands -- blocks the import. */
class RowFailed extends RuntimeException {}
