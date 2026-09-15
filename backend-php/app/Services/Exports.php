<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Shared export helpers -- CSV and Excel for list/report pages, so
 * every module's "export" button uses the same two small functions
 * instead of each one reinventing serialization. Mirrors
 * backend/app/services/exports.py one-for-one (`rows_to_csv` /
 * `rows_to_excel`), including its column-width sizing, so a converted
 * module's export is byte-comparable in shape to the Python one.
 *
 * Word (.docx) is handled per-document in App\Services\DocxForms,
 * since a single-record form (an invoice, a receipt) needs its own
 * layout rather than a generic row/column table -- same split as
 * Python.
 *
 * NOT the same class as App\Services\ExportService, deliberately:
 * that one predates the conversion, serves the NEW backend-php-only
 * report screens (Contract Operation Report, Sales Dashboard
 * drill-downs), and writes "Excel" as an HTML <table> with a .xls
 * name. That is fine for those screens but is NOT what Python's
 * /export.xlsx returns, so a converted endpoint cannot use it without
 * changing the API contract. Consolidating the two -- by moving those
 * screens onto this real-xlsx writer -- is follow-up work recorded in
 * docs/php-conversion-plan.md, not something to do silently here.
 */
class Exports
{
    /**
     * @param  array<int, string>  $fieldnames
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function rowsToCsv(array $fieldnames, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $fieldnames);
        foreach ($rows as $row) {
            fputcsv($out, array_map(
                static fn (string $f) => self::scalar($row[$f] ?? ''),
                $fieldnames
            ));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  array<int, string>  $fieldnames
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function rowsToExcel(array $fieldnames, array $rows, string $sheetName = 'Sheet1'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        // Excel's own sheet-name length limit, same truncation Python does.
        $sheet->setTitle(substr($sheetName, 0, 31));

        $sheet->fromArray($fieldnames, null, 'A1');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(
                array_map(static fn (string $f) => self::scalar($row[$f] ?? ''), $fieldnames),
                null,
                'A'.$r
            );
            $r++;
        }

        // Mirrors Python's width sizing: longest cell + 2, clamped to 10..50.
        foreach (range(1, count($fieldnames)) as $i) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $width = strlen((string) $fieldnames[$i - 1]);
            foreach ($rows as $row) {
                $width = max($width, strlen(self::scalar($row[$fieldnames[$i - 1]] ?? '')));
            }
            $sheet->getColumnDimension($letter)->setWidth(min(max($width + 2, 10), 50));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'websoft-xlsx-');
        (new Xlsx($book))->save($tmp);
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        $book->disconnectWorksheets();

        return $bytes;
    }

    /**
     * Python's csv.DictWriter / openpyxl write a bool as "True"/"False"
     * and None as "". PHP would give "1"/"" and null, so normalise here
     * rather than at every call site.
     */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        return $value === null ? '' : (string) $value;
    }
}
