<?php

namespace App\Services\DataMigration;

use RuntimeException;

/**
 * A row deliberately left behind (a draft or cancelled document, a
 * credit note) -- reported, but does not block the import.
 */
class RowSkipped extends RuntimeException {}
