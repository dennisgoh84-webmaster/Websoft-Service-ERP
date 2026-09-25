<?php

namespace App\Services\OdooMigration;

use Illuminate\Database\Eloquent\Model;

/**
 * One Odoo record type. The engine (OdooImporter) owns the run, the
 * transaction, the ID map and the report; an importer only turns one
 * OdooRecord into one Websoft record -- or throws RowFailed/RowSkipped.
 */
abstract class EntityImporter
{
    /** The `odoo:import` entity name, also the odoo_record_map.entity. */
    abstract public function entity(): string;

    /** The Websoft model the record becomes, as stored in odoo_record_map.target_type. */
    abstract public function targetType(Model $model): string;

    abstract public function import(OdooRecord $record, ImportContext $ctx): Model;

    /**
     * Odoo's External ID (`id` in an import-compatible export) -- the
     * key a re-run recognises the row by.
     */
    public function odooRef(OdooRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'external id');
    }

    /**
     * Group rows into records. One row per record by default; an
     * importer for a document with lines overrides this.
     *
     * @param  array<int, OdooRow>  $rows
     * @return array<int, OdooRecord>
     */
    public function records(array $rows): array
    {
        return array_map(fn (OdooRow $row) => new OdooRecord($row, [$row]), $rows);
    }

    /** A one-line description of the created record, for the report. */
    public function describe(Model $model): string
    {
        return (string) $model->getKey();
    }
}
