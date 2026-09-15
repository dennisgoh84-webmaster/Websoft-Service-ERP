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
 * Note this is App\Services\Exports (the port of Python's
 * exports.py -- real CSV, and a real .xlsx via PhpSpreadsheet), not
 * the older App\Services\ExportService, whose "Excel" is an HTML
 * table with a .xls name. New exports use this one; consolidating the
 * two is tracked in docs/php-conversion-plan.md.
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
