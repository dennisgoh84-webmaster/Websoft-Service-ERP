<?php

namespace App\Services\OdooMigration;

use RuntimeException;

/** A row that cannot be imported as it stands -- blocks a commit. */
class RowFailed extends RuntimeException {}
