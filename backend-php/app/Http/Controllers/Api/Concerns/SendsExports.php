<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\Exports;
use Illuminate\Http\Response;

/**
 * The two download responses every list screen's Export button needs.
 *
 * In backend/ this is written out inline in each router
 * (`StreamingResponse(iter([...]), media_type=..., headers={...})`);
 * collapsing it into one trait changes no behaviour -- the media
 * types and Content-Disposition filenames are identical -- it just
 * avoids repeating it across a dozen controllers.
 *
 * Everything goes through App\Services\Exports, the port of Python's
 * exports.py -- real CSV, and a real .xlsx via PhpSpreadsheet. The
 * older ExportService, whose "Excel" was an HTML table named .xls,
 * was retired 2026-09-15 and its two screens moved onto the table
 * helpers below.
 */
trait SendsExports
{
    private const XLSX_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function csvResponse(array $fields, array $rows, string $filename): Response
    {
        return response(Exports::rowsToCsv($fields, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    /**
     * The same two responses for a label-headed table -- see
     * App\Services\Exports::tableToCsv for when each shape applies.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    protected function csvTableResponse(array $headers, array $rows, string $filename): Response
    {
        return response(Exports::tableToCsv($headers, $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    protected function xlsxTableResponse(array $headers, array $rows, string $sheetName, string $filename): Response
    {
        return response(Exports::tableToExcel($headers, $rows, $sheetName), 200, [
            'Content-Type' => self::XLSX_MEDIA_TYPE,
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function xlsxResponse(array $fields, array $rows, string $sheetName, string $filename): Response
    {
        return response(Exports::rowsToExcel($fields, $rows, $sheetName), 200, [
            'Content-Type' => self::XLSX_MEDIA_TYPE,
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
