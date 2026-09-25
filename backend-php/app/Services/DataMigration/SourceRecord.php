<?php

namespace App\Services\DataMigration;

/**
 * One source record as read from an export: its first spreadsheet row,
 * plus -- for a document with lines (an ODOO quotation) -- the
 * continuation rows written beneath it, one per extra line, with the
 * header columns left blank.
 */
class SourceRecord
{
    /** @param  array<int, SourceRow>  $lineRows  every row of this record, the first included */
    public function __construct(public SourceRow $row, public array $lineRows) {}

    public function rowNumber(): int
    {
        return $this->row->number;
    }
}
