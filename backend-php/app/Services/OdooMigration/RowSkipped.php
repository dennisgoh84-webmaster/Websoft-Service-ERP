<?php

namespace App\Services\OdooMigration;

use RuntimeException;

/**
 * A row deliberately left behind (a draft or cancelled Odoo document,
 * a credit note) -- reported, but does not block a commit.
 */
class RowSkipped extends RuntimeException {}
