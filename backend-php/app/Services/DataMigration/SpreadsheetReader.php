<?php

namespace App\Services\DataMigration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads an export from the old system -- CSV (UTF-8, comma-separated)
 * or Excel (.xlsx) -- into SourceRows. The first row is the column
 * headings. Excel date cells come back as Y-m-d / Y-m-d H:i:s so both
 * formats reach the importers looking the same.
 */
class SpreadsheetReader
{
    /**
     * The column headings exactly as spelt in the file, blanks dropped.
     *
     * @return array<int, string>
     */
    public static function headers(string $path): array
    {
        $matrix = self::matrix($path);

        return array_values(array_filter(array_map(fn ($h) => trim((string) $h), $matrix[0] ?? []), fn ($h) => $h !== ''));
    }

    /** @return array<int, SourceRow> */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read {$path}.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $matrix = match ($extension) {
            'csv' => self::csv($path),
            'xlsx', 'xls' => self::excel($path),
            default => throw new \InvalidArgumentException('Expected a .csv or .xlsx export.'),
        };

        if ($matrix === []) {
            return [];
        }

        $headers = array_map(fn ($h) => SourceRow::normaliseHeader((string) $h), array_shift($matrix));
        $rows = [];
        foreach ($matrix as $i => $cells) {
            $values = [];
            foreach ($headers as $col => $header) {
                if ($header === '') {
                    continue;
                }
                $values[$header] = (string) ($cells[$col] ?? '');
            }
            $row = new SourceRow($i + 2, $values);
            if (! $row->isBlank()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private static function matrix(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('Cannot read the uploaded file.');
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv' => self::csv($path),
            'xlsx', 'xls' => self::excel($path),
            default => throw new \InvalidArgumentException('Upload an Excel (.xlsx) or CSV file.'),
        };
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
