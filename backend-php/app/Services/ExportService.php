<?php

namespace App\Services;

/**
 * Small shared CSV/Excel writer for report screens, per
 * docs/ui-guidelines.md section 2 ("Export (CSV / Excel) -- for every
 * list/report screen"). Used by the NEW report/dashboard features
 * built directly in backend-php (Contract Operation Report, Sales
 * Dashboard drill-downs) -- see docs/backlog.md / docs/planned-work.md.
 * backend-php has no equivalent yet to backend/app/services/exports.py
 * (CSV/Excel export is a tracked, not-yet-converted gap on every
 * existing backend-php module -- see docs/php-conversion-plan.md), so
 * this is a fresh, minimal implementation rather than a port.
 *
 * PRAGMATIC IMPLEMENTATION CHOICE: the "Excel" format is a plain HTML
 * `<table>` served with the `application/vnd.ms-excel` content type
 * and a `.xls` filename -- Excel opens this correctly (it is a long-
 * standing, widely used technique), and it avoids adding a real XLSX
 * writer library (e.g. phpoffice/phpspreadsheet) as a new dependency
 * for what these screens need, consistent with CLAUDE.md's "do not
 * introduce unnecessary dependencies" rule. Swap this for a real XLSX
 * writer if a native .xlsx file is ever actually required.
 */
class ExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function toExcelHtml(array $headers, array $rows): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $html = '<html><head><meta charset="UTF-8"></head><body><table border="1"><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>'.$esc($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.$esc($cell).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return $html;
    }

    public static function csvResponse(string $filename, array $headers, array $rows)
    {
        return response(self::toCsv($headers, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ]);
    }

    public static function excelResponse(string $filename, array $headers, array $rows)
    {
        return response(self::toExcelHtml($headers, $rows), 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => "attachment; filename=\"{$filename}.xls\"",
        ]);
    }
}
