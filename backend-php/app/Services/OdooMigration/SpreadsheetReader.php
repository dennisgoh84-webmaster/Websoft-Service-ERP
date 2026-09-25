<?php

namespace App\Services\OdooMigration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads an Odoo export -- CSV (UTF-8, comma-separated, as Odoo writes
 * it) or Excel (.xlsx, Odoo's default) -- into OdooRows. The first row
 * is the header. Excel date cells come back as Y-m-d / Y-m-d H:i:s so
 * both formats reach the importers looking the same.
 */
class SpreadsheetReader
{
    /** @return array<int, OdooRow> */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read {$path}.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $matrix = match ($extension) {
            'csv' => self::csv($path),
            'xlsx', 'xls' => self::excel($path),
            default => throw new \InvalidArgumentException('Expected a .csv or .xlsx Odoo export.'),
        };

        if ($matrix === []) {
            return [];
        }

        $headers = array_map(fn ($h) => OdooRow::normaliseHeader((string) $h), array_shift($matrix));
        $rows = [];
        foreach ($matrix as $i => $cells) {
            $values = [];
            foreach ($headers as $col => $header) {
                if ($header === '') {
                    continue;
                }
                $values[$header] = (string) ($cells[$col] ?? '');
            }
            $row = new OdooRow($i + 2, $values);
            if (! $row->isBlank()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private static function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        $matrix = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $matrix[] = array_map(fn ($c) => (string) $c, $cells);
        }
        fclose($handle);

        return $matrix;
    }

    /** @return array<int, array<int, string>> */
    private static function excel(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $matrix = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);
            foreach ($iterator as $cell) {
                $value = $cell->getValue();
                if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $date = ExcelDate::excelToDateTimeObject((float) $value);
                    $value = $date->format('H:i:s') === '00:00:00' ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
                } elseif (is_bool($value)) {
                    $value = $value ? 'True' : 'False';
                }
                $cells[] = $value === null ? '' : (string) $value;
            }
            $matrix[] = $cells;
        }

        return $matrix;
    }
}
