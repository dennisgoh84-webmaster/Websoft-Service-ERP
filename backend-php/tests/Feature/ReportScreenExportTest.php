<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two report screens built directly in `backend-php` -- the
 * Contract Operation Report and the Sales Dashboard drill-downs.
 *
 * They used to have their own writer (`App\Services\ExportService`,
 * retired 2026-09-15) whose "Excel" was an HTML `<table>` served with
 * a `.xls` name. Excel opens such a file but warns that its format and
 * extension disagree. They now go through `App\Services\Exports` like
 * every other screen, which is what docs/ui-guidelines.md section 2
 * asked for from the start ("never write a CSV/XLSX writer by hand").
 *
 * These tests exist to keep them there: an .xlsx that is secretly HTML
 * would pass any assertion that only checks the download succeeded.
 */
class ReportScreenExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function expiringContract(string $customerName = 'Acme Pte Ltd'): Contract
    {
        return Contract::factory()->for($this->company)->create([
            'customer_id' => CompanyIndividual::factory()->for($this->company)
                ->create(['name' => $customerName])->id,
            'status' => Contract::STATUS_EXPIRED,
            'end_date' => now()->subDays(5)->toDateString(),
        ]);
    }

    public function test_contract_operation_report_excel_is_a_real_xlsx_not_an_html_table(): void
    {
        $this->expiringContract();

        $response = $this->get('/api/reports/operations/contracts/expiry-listing/export.xlsx', $this->headers());

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $bytes = $response->getContent();
        // A real OOXML package is a ZIP. The old writer started "<html>".
        $this->assertStringStartsWith("PK\x03\x04", $bytes);
        $this->assertStringNotContainsString('<table', substr($bytes, 0, 200));

        $file = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $this->assertStringContainsString('ACME PTE LTD', $zip->getFromName('xl/sharedStrings.xml'));
        // The human column labels these screens use, not field keys.
        $this->assertStringContainsString('Contract Number', $zip->getFromName('xl/sharedStrings.xml'));
        $zip->close();
        unlink($file);
    }

    public function test_contract_operation_report_csv_keeps_its_human_column_labels(): void
    {
        $this->expiringContract();

        $response = $this->get('/api/reports/operations/contracts/expiry-listing/export.csv', $this->headers());

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('Contract Number,Company / Individual', $body);
        $this->assertStringContainsString('ACME PTE LTD', $body);
        // Python's CSV conventions now apply here too: CRLF records,
        // and no quoting of a field just because it contains a space.
        $this->assertStringContainsString("\r\n", $body);
        $this->assertStringNotContainsString('"Acme Pte Ltd"', $body);
    }

    public function test_renewal_due_listing_exports_in_both_formats(): void
    {
        $this->get('/api/reports/operations/contracts/renewal-due-listing/export.csv', $this->headers())
            ->assertOk();
        $this->get('/api/reports/operations/contracts/renewal-due-listing/export.xlsx', $this->headers())
            ->assertOk();
    }

    public function test_sales_dashboard_drilldowns_export_in_both_formats(): void
    {
        $paths = [
            '/api/sales-dashboard/ar-breakdown/export',
            '/api/sales-dashboard/top-billing-customers/export',
            '/api/sales-dashboard/bottom-non-active-customers/export',
        ];

        foreach ($paths as $path) {
            $csv = $this->get("{$path}.csv", $this->headers())->assertOk();
            $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));

            $xlsx = $this->get("{$path}.xlsx", $this->headers())->assertOk();
            $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
            $this->assertStringStartsWith("PK\x03\x04", $xlsx->getContent(), $path);
        }
    }

    public function test_the_old_xls_routes_are_gone(): void
    {
        // The screens now download .xlsx. Leaving the old path serving
        // xlsx bytes under a .xls name would reintroduce exactly the
        // format/extension mismatch this change removes.
        $this->get('/api/reports/operations/contracts/expiry-listing/export.xls', $this->headers())
            ->assertStatus(404);
        $this->get('/api/sales-dashboard/ar-breakdown/export.xls', $this->headers())
            ->assertStatus(404);
    }
}
