<?php

namespace App\Services\OdooMigration;

/**
 * One Odoo record as read from an export: its first spreadsheet row,
 * plus -- for a document with lines (a quotation) -- the continuation
 * rows Odoo writes beneath it, one per extra line, with the header
 * columns left blank.
 */
class OdooRecord
{
    /** @param  array<int, OdooRow>  $lineRows  every row of this record, the first included */
    public function __construct(public OdooRow $row, public array $lineRows) {}

    public function rowNumber(): int
    {
        return $this->row->number;
    }
}
