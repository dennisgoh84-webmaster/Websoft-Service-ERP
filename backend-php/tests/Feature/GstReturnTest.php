<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\GstReturn;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The GST F5 workflow (Dennis, 2026-09-26, open item 4b.4): lock the
 * month's period, run GST Calculation, and the Form 5 figures and the
 * documents behind them are kept; the GST Return and its supporting
 * listing read only what was kept.
 */
class GstReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private array $h;

    private CompanyIndividual $customer;

    private CompanyIndividual $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $this->h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token')];
        $this->customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme']);
        $this->supplier = CompanyIndividual::factory()->for($this->company)->create(['is_supplier' => true, 'name' => 'Parts Co']);
    }

    private function period(string $start, string $end, string $name): string
    {
        return $this->postJson('/api/accounting-periods', [
            'fiscal_year' => 2027, 'name' => $name, 'period_start' => $start, 'period_end' => $end,
        ], $this->h)->assertOk()->json('id');
    }

    private function invoice(string $issuedAt, string $code, float $net, float $gst, array $extra = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.fake()->unique()->numerify('#####'), 'invoice_type' => Invoice::TYPE_SALES,
            'description' => 'x', 'amount_sgd' => $net, 'tax_code' => $code, 'gst_rate' => $gst > 0 ? 9 : 0,
            'gst_amount_sgd' => $gst, 'total_amount_sgd' => $net + $gst,
        ], $extra));
        $invoice->forceFill(['issued_at' => $issuedAt])->save();

        return $invoice;
    }

    private function bill(string $date, float $net, float $gst, string $status = SupplierInvoice::STATUS_APPROVED, ?string $code = null): SupplierInvoice
    {
        return SupplierInvoice::factory()->create([
            'company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'invoice_date' => $date,
            'amount_sgd' => $net, 'gst_amount_sgd' => $gst, 'total_amount_sgd' => $net + $gst, 'status' => $status,
            'tax_code' => $code ?? ($gst > 0 ? 'TX' : 'NR'),
        ]);
    }

    /** September 2026 with one of every kind of document, some that count and some that must not. */
    private function september(): string
    {
        $id = $this->period('2026-09-01', '2026-09-30', 'Sep 2026');
        $this->invoice('2026-09-05 10:00:00+08', 'SR', 1000, 90);
        $this->invoice('2026-09-10 10:00:00+08', 'SR', 500, 45, ['status' => Invoice::STATUS_WRITTEN_OFF]); // still a supply
        $this->invoice('2026-09-12 10:00:00+08', 'ZR', 300, 0);
        $this->invoice('2026-09-15 10:00:00+08', 'ES', 200, 0);
        $this->invoice('2026-09-18 10:00:00+08', 'OS', 100, 0); // revenue only
        $this->invoice('2026-09-30 23:30:00+08', 'SR', 100, 9); // 30 Sep in Singapore, though 1 Oct UTC... still Sep
        $this->invoice('2026-10-01 00:30:00+08', 'SR', 7000, 630); // next month in Singapore
        $this->invoice('2026-09-20 10:00:00+08', 'SR', 9999, 899.91, ['migrated_at' => now()]); // old system's, already filed there
        $this->bill('2026-09-08', 400, 36);
        $this->bill('2026-09-09', 50, 0); // NR: a supplier not registered for GST
        $this->bill('2026-09-10', 70, 0, code: 'ZP'); // zero-rated purchase: taxable, box 5
        $this->bill('2026-09-11', 800, 72, SupplierInvoice::STATUS_EXCEPTION); // not in the books
        $this->bill('2026-10-02', 600, 54); // next month

        return $id;
    }

    public function test_the_calculation_needs_the_period_locked(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)
            ->assertStatus(409)->assertJsonFragment(['detail' => 'Lock the period first: Sep 2026 is still open, so its figures can still change. Close All, then run the GST Calculation.']);
        $this->assertDatabaseCount('gst_returns', 0);
    }

    public function test_the_calculation_sums_the_locked_period_into_the_form_5_boxes_and_keeps_its_documents(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();

        $r = $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertOk()->json();

        $box = collect($r['boxes'])->pluck('amount_sgd', 'box');
        $this->assertEquals(1600, $box[1], 'standard-rated: 1000 + 500 written off + 100 late on the 30th');
        $this->assertEquals(300, $box[2]);
        $this->assertEquals(200, $box[3]);
        $this->assertEquals(2100, $box[4]);
        $this->assertEquals(470, $box[5], 'TX 400 + ZP 70; NR is not a taxable purchase');
        $this->assertEquals(144, $box[6]);
        $this->assertEquals(36, $box[7]);
        $this->assertEquals(108, $box[8]);
        $this->assertEquals(2200, $box[13], 'revenue includes out-of-scope');
        $this->assertSame(6, $r['output_document_count']);
        $this->assertSame(3, $r['input_document_count']);
        $this->assertCount(9, $r['lines']);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'gst_return', 'entity_id' => $r['id'], 'action' => 'calculated']);

        // The period list shows it.
        $this->getJson('/api/accounting-periods', $this->h)->assertOk()->assertJsonPath('0.gst.net_gst_sgd', 108);
    }

    public function test_recalculating_keeps_the_earlier_version(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();
        $first = $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->json('id');
        $second = $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertOk()->assertJsonPath('version', 2)->json('id');

        $this->assertSame(GstReturn::STATUS_SUPERSEDED, GstReturn::find($first)->status);
        $this->assertSame(GstReturn::STATUS_CURRENT, GstReturn::find($second)->status);
        $this->getJson("/api/accounting-periods/{$id}/gst", $this->h)->assertOk()
            ->assertJsonPath('current.version', 2)->assertJsonCount(2, 'history');
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'gst_return', 'entity_id' => $second, 'action' => 'recalculated']);
    }

    public function test_the_reports_read_what_was_kept_not_the_live_documents(): void
    {
        $sep = $this->september();
        $this->period('2026-10-01', '2026-10-31', 'Oct 2026');
        $this->postJson("/api/accounting-periods/{$sep}/close", [], $this->h)->assertOk();
        $this->postJson("/api/accounting-periods/{$sep}/gst-calculate", [], $this->h)->assertOk();

        // A document changed behind the locked period's back does not move the saved figures.
        DB::table('invoices')->where('tax_code', 'ZR')->update(['amount_sgd' => 99999]);

        $q = '?period_start=2026-09-01&period_end=2026-10-31';
        $report = $this->getJson("/api/reports/accounting/gst-return{$q}", $this->h)->assertOk();
        $box = collect($report->json('boxes'))->pluck('amount_sgd', 'box');
        $this->assertEquals(300, $box[2]);
        $this->assertEquals(108, $report->json('net_gst_payable_sgd'));
        $this->assertCount(1, $report->json('periods'));
        // October is in the range but not calculated yet -- named, not summed as zero.
        $report->assertJsonPath('missing_periods.0.period_name', 'Oct 2026');

        $supporting = $this->getJson("/api/reports/accounting/gst-supporting{$q}&direction=input", $this->h)->assertOk();
        $this->assertCount(3, $supporting->json('rows'));
        $this->assertSame(['5', '5', 'not_taxable'], collect($supporting->json('rows'))->pluck('box')->sort()->values()->all());
        $this->assertSame('PARTS CO', $supporting->json('rows.0.party_name'));

        $csv = $this->get("/api/reports/accounting/gst-supporting/export.csv{$q}", $this->h)->assertOk()->getContent();
        $this->assertStringContainsString('period,direction,document_number,document_date,party_name,tax_code,box,net_sgd,gst_sgd', $csv);
        $this->assertStringContainsString('Sep 2026,output', $csv);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'report_generated', 'details' => 'Accounting Report: GST Supporting Listing exported as CSV (9 rows)']);
    }

    public function test_once_submitted_to_iras_the_month_is_locked(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/gst-submit", [], $this->h)->assertStatus(409); // nothing calculated yet
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();
        $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertOk();

        $r = $this->postJson("/api/accounting-periods/{$id}/gst-submit", [], $this->h)->assertOk()->json();
        $this->assertNotNull($r['submitted_at']);
        $this->assertNotNull($r['submitted_by_name']);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'gst_return', 'entity_id' => $r['id'], 'action' => 'submitted_to_iras']);
        $this->getJson('/api/accounting-periods', $this->h)->assertJsonPath('0.gst.submitted_by_name', $r['submitted_by_name']);

        // Locked: no recalculation, no second submission, no reopening, no lifting any lock.
        $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertStatus(409);
        $this->postJson("/api/accounting-periods/{$id}/gst-submit", [], $this->h)->assertStatus(409);
        $this->postJson("/api/accounting-periods/{$id}/reopen", [], $this->h)->assertStatus(409);
        $this->postJson("/api/accounting-periods/{$id}/toggle-lock", ['doc_type' => 'sales_invoice', 'operation' => 'update', 'locked' => false], $this->h)
            ->assertStatus(409);
        $this->assertSame('closed', DB::table('accounting_periods')->where('id', $id)->value('status'));
    }

    public function test_a_submitted_return_can_be_revised_and_resubmitted_keeping_the_old_one(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();
        $v1 = $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertOk()->json();
        $this->postJson("/api/accounting-periods/{$id}/gst-submit", [], $this->h)->assertOk();

        // A reason is required, and only a submitted return can be revised.
        $this->postJson("/api/accounting-periods/{$id}/gst-revise", ['reason' => '  '], $this->h)->assertStatus(422);
        $opened = $this->postJson("/api/accounting-periods/{$id}/gst-revise", ['reason' => 'Missed a supplier bill'], $this->h)->assertOk()->json();
        $this->assertSame('Missed a supplier bill', $opened['revision_reason']);
        $this->assertNotNull($opened['revision_opened_by_name']);
        $this->assertNotNull($opened['submitted_at'], 'the submitted return keeps its submission');
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'gst_return', 'entity_id' => $v1['id'], 'action' => 'revision_opened']);
        $this->postJson("/api/accounting-periods/{$id}/gst-revise", ['reason' => 'again'], $this->h)->assertStatus(409);
        // The submitted version itself cannot be submitted again.
        $this->postJson("/api/accounting-periods/{$id}/gst-submit", [], $this->h)->assertStatus(409);

        // The month opens for the correction, the missed bill goes in, and it is locked again.
        $this->postJson("/api/accounting-periods/{$id}/reopen", [], $this->h)->assertOk();
        $this->bill('2026-09-25', 1000, 90);
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();

        $v2 = $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertOk()->json();
        $this->assertSame(2, $v2['version']);
        $this->assertSame(1, $v2['revises_version']);
        $box7 = fn (array $r) => collect($r['boxes'])->firstWhere('box', 7)['amount_sgd'];
        $this->assertEqualsWithDelta($box7($v1) + 90, $box7($v2), 0.001);

        $resubmitted = $this->postJson("/api/accounting-periods/{$id}/gst-submit", [], $this->h)->assertOk()->json();
        $this->assertNotNull($resubmitted['submitted_at']);

        // Locked again, and the first submission is still on file as it was.
        $this->postJson("/api/accounting-periods/{$id}/reopen", [], $this->h)->assertStatus(409);
        $history = collect($this->getJson("/api/accounting-periods/{$id}/gst", $this->h)->assertOk()->json('history'));
        $old = $history->firstWhere('version', 1);
        $this->assertSame('superseded', $old['status']);
        $this->assertNotNull($old['submitted_at']);
        $this->assertSame('Missed a supplier bill', $old['revision_reason']);
        $this->assertEqualsWithDelta($box7($v1), $box7($old), 0.001);
        $this->assertSame(1, $history->firstWhere('version', 2)['revises_version']);
    }

    public function test_only_a_submitted_return_can_be_revised(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/gst-revise", ['reason' => 'x'], $this->h)->assertStatus(409);
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();
        $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $this->h)->assertOk();
        $this->postJson("/api/accounting-periods/{$id}/gst-revise", ['reason' => 'x'], $this->h)->assertStatus(409);
    }

    public function test_a_view_only_group_can_read_but_not_calculate(): void
    {
        $id = $this->september();
        $this->postJson("/api/accounting-periods/{$id}/close", [], $this->h)->assertOk();

        $group = Group::factory()->for($this->company)->create();
        ModuleCatalog::firstOrCreate(['key' => 'finance_accounting'], ['name' => 'Finance', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => 'finance_accounting'], ['enabled' => true]);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'finance_accounting', 'access_level' => GroupModuleAuthority::VIEW]);
        $clerk = User::factory()->for($this->company)->create(['role' => User::ROLE_FINANCE, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $clerk->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $clerk->email, 'password' => 'demo1234'])->json('access_token')];

        $this->postJson("/api/accounting-periods/{$id}/gst-calculate", [], $h)->assertStatus(403);
        $this->postJson("/api/accounting-periods/{$id}/gst-revise", ['reason' => 'x'], $h)->assertStatus(403);
        $this->getJson("/api/accounting-periods/{$id}/gst", $h)->assertOk()->assertJsonPath('current', null);
    }
}
