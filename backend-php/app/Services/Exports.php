<?php

namespace App\Services;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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
 * This is now the ONLY export writer in `backend-php`. It replaced
 * App\Services\ExportService (retired 2026-09-15), which predated the
 * conversion and wrote "Excel" as an HTML <table> served with a .xls
 * name -- a real file Excel opens, but one it also warns about, and
 * not what Python's /export.xlsx returns. The two report screens that
 * used it (Contract Operation Report, Sales Dashboard drill-downs)
 * now go through `tableToCsv`/`tableToExcel` below, which is what
 * docs/ui-guidelines.md section 2 asked for all along ("never write a
 * CSV/XLSX writer by hand").
 *
 * DATES (Dennis, 2026-09-25: DD/MM/YYYY in CSV and Excel too): every
 * value that IS a date -- a DateTime, or a string that is exactly
 * YYYY-MM-DD or an ISO date-time -- is written as DD/MM/YYYY, and a
 * moment in time as DD/MM/YYYY HH:MM in the app's timezone. In Excel it
 * is a real date cell formatted dd/mm/yyyy, so it still sorts and
 * filters as a date. Only the whole value is matched: "INV-2026-09-25"
 * or a note that mentions a date are left alone. This deliberately
 * departs from the Python exports' ISO dates.
 */
class Exports
{
    /**
     * @param  array<int, string>  $fieldnames
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function rowsToCsv(array $fieldnames, array $rows): string
    {
        $csv = self::csvLine($fieldnames);
        foreach ($rows as $row) {
            $csv .= self::csvLine(array_map(
                static fn (string $f) => self::scalar($row[$f] ?? ''),
                $fieldnames
            ));
        }

        return $csv;
    }

    /**
     * One CSV record, written the way Python's `csv.writer` writes it
     * with its defaults -- which is what this is a port of.
     *
     * Deliberately NOT `fputcsv()`: PHP also quotes a field that
     * merely contains a SPACE, and terminates lines with "\n", where
     * Python quotes only when the field contains the delimiter, a
     * quote or a line break (QUOTE_MINIMAL) and terminates with
     * "\r\n". Both files open the same in Excel, but "Acme Pte Ltd"
     * coming back quoted from one backend and bare from the other is
     * exactly the kind of drift this conversion is meant not to
     * introduce.
     *
     * @param  array<int, string>  $values
     */
    private static function csvLine(array $values): string
    {
        $fields = array_map(static function (string $value): string {
            if (preg_match('/[",\r\n]/', $value) === 1) {
                return '"'.str_replace('"', '""', $value).'"';
            }

            return $value;
        }, $values);

        return implode(',', $fields)."\r\n";
    }

    /**
     * The same two writers, for a table that comes as a header row of
     * human labels plus positional rows, rather than field keys plus
     * dictionaries.
     *
     * The converted endpoints use the `rows*` pair above because their
     * header row has to be Python's field names, column for column.
     * The report screens built directly in `backend-php` (Contract
     * Operation Report, Sales Dashboard drill-downs) have no Python
     * counterpart and label their columns for a reader -- "Contract
     * Number", not "contract_number". Both shapes go through the same
     * writer so neither can drift.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function tableToCsv(array $headers, array $rows): string
    {
        return self::rowsToCsv($headers, self::keyRows($headers, $rows));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function tableToExcel(array $headers, array $rows, string $sheetName = 'Sheet1'): string
    {
        return self::rowsToExcel($headers, self::keyRows($headers, $rows), $sheetName);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function keyRows(array $headers, array $rows): array
    {
        return array_map(
            static fn (array $row) => array_combine($headers, array_pad(
                array_slice(array_values($row), 0, count($headers)),
                count($headers),
                ''
            )),
            $rows
        );
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
            foreach ($fieldnames as $i => $f) {
                $coord = Coordinate::stringFromColumnIndex($i + 1).$r;
                $date = self::asDate($row[$f] ?? '');
                if ($date !== null) {
                    $sheet->setCellValue($coord, ExcelDate::PHPToExcel($date['at']));
                    $sheet->getStyle($coord)->getNumberFormat()
                        ->setFormatCode($date['time'] ? 'dd/mm/yyyy hh:mm' : 'dd/mm/yyyy');
                } else {
                    $sheet->setCellValue($coord, self::scalar($row[$f] ?? ''));
                }
            }
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
        $date = self::asDate($value);
        if ($date !== null) {
            return $date['at']->format($date['time'] ? 'd/m/Y H:i' : 'd/m/Y');
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * The value as a date, if it is one: a DateTime, "YYYY-MM-DD", or an
     * ISO date-time ("YYYY-MM-DD HH:MM[:SS]", "...T...Z", "...+08:00").
     * A plain date stays that calendar day; a moment in time is shown in
     * the app's timezone (Asia/Singapore on the servers).
     *
     * @return array{at: \DateTimeInterface, time: bool}|null
     */
    private static function asDate(mixed $value): ?array
    {
        if ($value instanceof \DateTimeInterface) {
            // A date column (Eloquent 'date' cast) is midnight in its own zone.
            if ($value->format('H:i:s') === '00:00:00') {
                return ['at' => $value, 'time' => false];
            }

            return ['at' => Carbon::instance($value)->timezone(config('app.timezone')), 'time' => true];
        }
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['at' => Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay(), 'time' => false];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1) {
            try {
                return ['at' => Carbon::parse($value, config('app.timezone'))->timezone(config('app.timezone')), 'time' => true];
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
