<?php

namespace App\Services\DataMigration;

use App\Services\DataMigration\Importers\CompanyIndividualsImporter;
use App\Services\DataMigration\Importers\ContractsImporter;
use App\Services\DataMigration\Importers\InvoicesImporter;
use App\Services\DataMigration\Importers\JobOrdersImporter;
use App\Services\DataMigration\Importers\QuotationsImporter;
use App\Services\DataMigration\Importers\ReceiptsImporter;
use App\Services\DataMigration\Importers\ServiceRecordsImporter;

/**
 * Every module involved in the migration (docs/data-migration.md), in
 * the order they should run: each resolves references to the ones
 * above it (a contract needs its Company / Individual first). ODOO
 * runs first, so ZSOFT's Company / Individual rows can link to the
 * ones ODOO already brought in instead of duplicating them.
 *
 * No General Ledger data comes from either system (decided
 * 2026-09-25): the ledger starts afresh from a management-accounts
 * opening-balance Journal Voucher keyed in here.
 */
class MigrationCatalog
{
    public const SOURCES = ['odoo' => 'ODOO', 'zsoft' => 'ZSOFT'];

    /** entity => importer class */
    public const IMPORTERS = [
        'company_individuals' => CompanyIndividualsImporter::class,
        'contracts' => ContractsImporter::class,
        'quotations' => QuotationsImporter::class,
        'job_orders' => JobOrdersImporter::class,
        'invoices' => InvoicesImporter::class,
        'receipts' => ReceiptsImporter::class,
        'service_records' => ServiceRecordsImporter::class,
    ];

    /** [source, entity, the module's name in the old system] in run order */
    public const MODULES = [
        ['odoo', 'company_individuals', 'Contacts'],
        ['odoo', 'contracts', 'Subscriptions'],
        ['odoo', 'quotations', 'Sales Quotations'],
        ['odoo', 'invoices', 'Sales Invoices'],
        ['odoo', 'receipts', 'Customer Payments'],
        ['odoo', 'service_records', 'Timesheets'],
        ['zsoft', 'company_individuals', 'Customers'],
        ['zsoft', 'contracts', 'Contracts'],
        ['zsoft', 'job_orders', 'Job Orders'],
        ['zsoft', 'service_records', 'Service Records'],
        ['zsoft', 'invoices', 'Past Invoices'],
    ];

    public static function importer(string $entity): EntityImporter
    {
        $class = self::IMPORTERS[$entity] ?? throw new \InvalidArgumentException("Unknown module \"{$entity}\".");

        return new $class;
    }

    /** @return array{source: string, entity: string, their_name: string, order: int}|null */
    public static function module(string $source, string $entity): ?array
    {
        foreach (self::MODULES as $i => [$s, $e, $theirs]) {
            if ($s === $source && $e === $entity) {
                return ['source' => $s, 'entity' => $e, 'their_name' => $theirs, 'order' => $i + 1];
            }
        }

        return null;
    }

    public static function requireModule(string $source, string $entity): array
    {
        return self::module($source, $entity)
            ?? throw new \InvalidArgumentException('That module is not part of the '.(self::SOURCES[$source] ?? $source).' migration.');
    }

    /**
     * The field each column heading maps to automatically: the heading
     * matches a field key or one of its aliases (ignoring case and
     * spacing); null when nothing matches.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string|null>
     */
    public static function autoMap(EntityImporter $importer, array $headers): array
    {
        $lookup = [];
        foreach ($importer->fields() as $key => $field) {
            foreach ([$key, ...($field['aliases'] ?? [])] as $alias) {
                $lookup[SourceRow::normaliseHeader($alias)] ??= $key;
            }
        }
        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            $key = $lookup[SourceRow::normaliseHeader($header)] ?? null;
            // One column per field: a second heading that would map to
            // the same field is left for the user to decide.
            if ($key !== null && isset($used[$key])) {
                $key = null;
            }
            if ($key !== null) {
                $used[$key] = true;
            }
            $mapping[$header] = $key;
        }

        return $mapping;
    }
}
