<?php

namespace App\Services\DataMigration;

use Illuminate\Database\Eloquent\Model;

/**
 * One module of the migration (Company / Individual, Contracts, ...).
 * The engine owns the batch, the transaction, the record map and the
 * report; an importer declares the fields it can fill and turns one
 * SourceRecord -- already re-keyed by those field keys through the
 * field mapping -- into one record here, or throws
 * RowFailed/RowSkipped/NeedsDecision.
 */
abstract class EntityImporter
{
    /** The module key, also migration_record_map.entity. */
    abstract public function entity(): string;

    /** The module as this system names it, e.g. "Company / Individual". */
    abstract public function label(): string;

    /**
     * The fields a source column can be mapped to, keyed by field key.
     * `aliases` are column headings that map to it automatically
     * (ODOO's import-compatible names and labels, ZSOFT's once known).
     *
     * @return array<string, array{label: string, required?: bool, aliases?: array<int, string>, hint?: string}>
     */
    abstract public function fields(): array;

    /** The record type stored in migration_record_map.target_type. */
    abstract public function targetType(Model $model): string;

    abstract public function import(SourceRecord $record, ImportContext $ctx): Model;

    /**
     * The key a re-run recognises the row by: the mapped Source ID,
     * else the importer's natural key (the document number the old
     * system printed).
     */
    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id');
    }

    /**
     * Group rows into records. One row per record by default; an
     * importer for a document with lines overrides this.
     *
     * @param  array<int, SourceRow>  $rows
     * @return array<int, SourceRecord>
     */
    public function records(array $rows): array
    {
        return array_map(fn (SourceRow $row) => new SourceRecord($row, [$row]), $rows);
    }

    /** A one-line description of the created record, for the report. */
    public function describe(Model $model): string
    {
        return (string) $model->getKey();
    }

    /** The Source ID field every module shares. */
    protected static function sourceIdField(string $hint = ''): array
    {
        return ['id' => [
            'label' => 'Source ID',
            'aliases' => ['id', 'external id'],
            'hint' => $hint ?: 'The old system\'s own id for the row. Lets a re-upload skip rows already imported.',
        ]];
    }

    /** The Company / Individual reference fields every document shares. */
    protected static function partyFields(): array
    {
        return [
            'partner_id/id' => [
                'label' => 'Company / Individual: Source ID',
                'aliases' => ['partner_id/id', 'customer/external id', 'partner/external id', 'customer code', 'cust_code', 'customer id'],
                'hint' => 'The Source ID the Company / Individual was imported with (preferred).',
            ],
            'partner_id' => [
                'label' => 'Company / Individual: name',
                'aliases' => ['partner_id', 'customer', 'partner', 'customer name', 'cust_name'],
                'hint' => 'Used when there is no Source ID column. Must match exactly one name.',
            ],
        ];
    }

    protected static function currencyField(): array
    {
        return ['currency_id' => ['label' => 'Currency', 'aliases' => ['currency_id', 'currency'], 'hint' => 'Only SGD rows can be imported.']];
    }
}
