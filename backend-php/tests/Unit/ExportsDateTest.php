<?php

namespace Tests\Unit;

use App\Services\Exports;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * DD/MM/YYYY in every CSV and Excel export (Dennis, 2026-09-25) -- the
 * one place every export goes through, App\Services\Exports.
 */
class ExportsDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Singapore']);
    }

    public function test_csv_writes_dates_as_dd_mm_yyyy_and_leaves_everything_else_alone(): void
    {
        $csv = Exports::rowsToCsv(['date', 'utc_at', 'local_at', 'number', 'amount', 'bad', 'carbon_date', 'carbon_at'], [[
            'date' => '2026-09-25',
            'utc_at' => '2026-09-25T06:32:19.000000Z',
            'local_at' => '2026-09-25 14:32:19',
            'number' => 'INV-2026-09-25',
            'amount' => '12.50',
            'bad' => '2026-02-30',
            'carbon_date' => Carbon::create(2026, 9, 25),
            'carbon_at' => Carbon::parse('2026-09-25T06:32:19Z'),
        ]]);

        $this->assertSame(
            "25/09/2026,25/09/2026 14:32,25/09/2026 14:32,INV-2026-09-25,12.50,2026-02-30,25/09/2026,25/09/2026 14:32\r\n",
            explode("\r\n", $csv, 2)[1],
        );
    }

    public function test_excel_writes_real_date_cells_shown_as_dd_mm_yyyy(): void
    {
        $bytes = Exports::rowsToExcel(['date', 'at', 'amount', 'number'], [
            ['date' => '2026-09-25', 'at' => '2026-09-25T06:32:19Z', 'amount' => '12.50', 'number' => 'INV-2026-09-25'],
        ]);
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $bytes);
        $sheet = IOFactory::load($file)->getActiveSheet();
        unlink($file);

        // A number underneath, so it sorts and filters as a date in Excel.
        $this->assertSame('n', $sheet->getCell('A2')->getDataType());
        $this->assertSame('25/09/2026', $sheet->getCell('A2')->getFormattedValue());
        $this->assertSame('25/09/2026 14:32', $sheet->getCell('B2')->getFormattedValue());
        $this->assertSame('s', $sheet->getCell('D2')->getDataType());
        $this->assertSame('INV-2026-09-25', $sheet->getCell('D2')->getValue());
    }
}
