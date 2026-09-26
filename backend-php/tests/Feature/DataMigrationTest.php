<?php

namespace Tests\Feature;

use App\Exceptions\PostingError;
use App\Models\AuditLogEntry;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\JournalEntry;
use App\Models\MigrationBatch;
use App\Models\MigrationMapping;
use App\Models\MigrationRecordMap;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\ServiceRecord;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\Audit;
use App\Services\DataMigration\MigrationBatches;
use App\Services\DataMigration\MigrationEngine;
use App\Services\DataMigration\MigrationMappings;
use App\Services\DataMigration\MigrationRollback;
use App\Services\Posting;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Data Migration (docs/data-migration.md): ODOO and ZSOFT files go
 * upload -> map -> dry run -> import -> roll back. Dry run by default,
 * all or nothing on import, re-runnable through the record map,
 * duplicates linked or decided rather than created twice, and invoices
 * and receipts history only -- nothing posts to the General Ledger.
 */
class DataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'full_name' => 'Dennis Goh']);
        foreach ([['SR', '9.00'], ['ZR', '0.00'], ['OS', '0.00']] as [$code, $rate]) {
            TaxCode::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'rate_percent' => $rate]);
        }
    }

    /** @param  array<int, array<int, string>>  $rows  header first */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mig').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function upload(string $source, string $entity, array $rows): MigrationBatch
    {
        return MigrationBatches::upload($this->company, $source, $entity, $this->csv($rows), $this->owner);
    }

    /**
     * Upload, dry run, and -- when asked and the dry run is clean --
     * sign the Field Gap off and import, as a user would.
     *
     * @param  array<string, string>  $decisions
     */
    private function migrate(string $source, string $entity, array $rows, bool $commit = true, array $decisions = []): MigrationBatch
    {
        $batch = $this->upload($source, $entity, $rows);
        if ($decisions !== []) {
            MigrationBatches::saveDecisions($batch, $decisions, $this->owner);
        }
        $batch = MigrationBatches::startDryRun($batch, $this->owner);
        if (! $commit || $batch->rows_failed + $batch->rows_needs_decision > 0 || $batch->status !== MigrationBatch::STATUS_DRY_RUN) {
            return $batch;
        }
        $mapping = MigrationMappings::get($this->company->id, $source, $entity);
        if ($mapping->signed_off_at === null) {
            MigrationMappings::signOff($mapping, $this->owner);
        }

        return MigrationBatches::startImport($batch->fresh(), $this->owner);
    }

    /** @return array<int, string> */
    private function outcomes(MigrationBatch $batch): array
    {
        return array_column($batch->report, 'outcome');
    }

    private function importOdooContacts(): void
    {
        $batch = $this->migrate('odoo', 'company_individuals', [
            ['id', 'name', 'is_company', 'email', 'parent_id/id', 'customer_rank', 'supplier_rank', 'property_payment_term_id', 'ref', 'company_registry'],
            ['__export__.res_partner_2', 'Jane Tan', '0', 'jane@acme.test', '__export__.res_partner_1', '0', '0', '', '', ''],
            ['__export__.res_partner_1', 'Acme Pte Ltd', '1', 'ap@acme.test', '', '3', '0', '30 Days', 'C0001', '201912345K'],
            ['__export__.res_partner_3', 'Bolt Supplies', '1', '', '', '0', '2', 'End of Following Month', '', ''],
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
    }

    private function acme(): CompanyIndividual
    {
        return CompanyIndividual::where('name', 'ACME PTE LTD')->firstOrFail();
    }

    // ---- flow ------------------------------------------------------------

    public function test_upload_reads_the_columns_and_maps_the_known_ones(): void
    {
        $batch = $this->upload('zsoft', 'company_individuals', [
            ['CUST_CODE', 'CUST_NAME', 'ROC_NO', 'CREDIT_LIMIT'],
            ['C001', 'Harbour Logistics Pte Ltd', '201912345K', '50000'],
        ]);

        $this->assertSame(MigrationBatch::STATUS_UPLOADED, $batch->status);
        $this->assertSame(1, $batch->rows_read);
        $this->assertStringStartsWith('MIG-', $batch->batch_number);
        $status = MigrationMappings::status(MigrationMappings::get($this->company->id, 'zsoft', 'company_individuals'));
        $fields = array_column($status['columns'], 'field', 'header');
        $this->assertSame(['CUST_CODE' => 'ref', 'CUST_NAME' => 'name', 'ROC_NO' => 'company_registry', 'CREDIT_LIMIT' => null], $fields);
        $this->assertSame(1, $status['undecided']);
        $this->assertFalse($status['can_sign_off']);
        $this->assertTrue(AuditLogEntry::where('entity_id', $batch->id)->where('action', 'data_migration_uploaded')->exists());
    }

    public function test_dry_run_reports_everything_and_writes_nothing(): void
    {
        $batch = $this->migrate('odoo', 'company_individuals', [['id', 'name', 'is_company'], ['p1', 'Acme Pte Ltd', '1']], commit: false);

        $this->assertSame(MigrationBatch::STATUS_DRY_RUN, $batch->status);
        $this->assertSame(1, $batch->rows_created);
        $this->assertSame(0, CompanyIndividual::count());
        $this->assertSame(0, MigrationRecordMap::count());
        $this->assertTrue(AuditLogEntry::where('entity_id', $batch->id)->where('action', 'data_migration_dry_run')->exists());
    }

    public function test_import_is_locked_until_the_field_gap_list_is_signed_off(): void
    {
        $batch = $this->upload('zsoft', 'company_individuals', [['CUST_CODE', 'CUST_NAME', 'CREDIT_LIMIT'], ['C001', 'Harbour', '5000']]);
        $batch = MigrationBatches::startDryRun($batch, $this->owner);
        $this->assertSame(MigrationBatch::STATUS_DRY_RUN, $batch->status);

        $mapping = MigrationMappings::get($this->company->id, 'zsoft', 'company_individuals');
        try {
            MigrationBatches::startImport($batch, $this->owner);
            $this->fail('import should be locked');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Field Gap', $e->getMessage());
        }

        // A Field Gap still blocks the sign-off...
        MigrationMappings::update($mapping, ['CREDIT_LIMIT' => MigrationMapping::NEW_FIELD], $this->owner);
        try {
            MigrationMappings::signOff($mapping->fresh(), $this->owner);
            $this->fail('a Field Gap should block sign-off');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Field Gap', $e->getMessage());
        }

        // ...leaving the column out on purpose lets it through.
        MigrationMappings::update($mapping->fresh(), ['CREDIT_LIMIT' => MigrationMapping::SKIP], $this->owner);
        MigrationMappings::signOff($mapping->fresh(), $this->owner);
        $batch = MigrationBatches::startImport($batch->fresh(), $this->owner);

        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $this->assertSame('C001', CompanyIndividual::firstOrFail()->legacy_customer_code);
    }

    public function test_changing_the_mapping_clears_the_sign_off_and_needs_a_new_dry_run(): void
    {
        $batch = $this->migrate('odoo', 'company_individuals', [['id', 'name', 'email'], ['p1', 'Acme', 'a@x.test']], commit: false);
        $mapping = MigrationMappings::get($this->company->id, 'odoo', 'company_individuals');
        MigrationMappings::signOff($mapping, $this->owner);

        MigrationMappings::update($mapping->fresh(), ['email' => MigrationMapping::SKIP], $this->owner);
        $this->assertNull($mapping->fresh()->signed_off_at);

        MigrationMappings::signOff($mapping->fresh(), $this->owner);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dry run again');
        MigrationBatches::startImport($batch->fresh(), $this->owner);
    }

    public function test_one_failed_row_means_nothing_is_written(): void
    {
        $batch = $this->upload('odoo', 'company_individuals', [['id', 'name'], ['p1', 'Acme'], ['p2', '']]);
        $batch->forceFill(['mapping' => ['id' => 'id', 'name' => 'name']])->save();

        $batch = MigrationEngine::run($batch, true, $this->owner);

        $this->assertSame(MigrationBatch::STATUS_FAILED, $batch->status);
        $this->assertSame(['created', 'failed'], $this->outcomes($batch));
        $this->assertSame(0, CompanyIndividual::count());
    }

    public function test_a_required_field_with_no_column_stops_the_run(): void
    {
        $batch = $this->migrate('odoo', 'contracts', [['id', 'partner_id'], ['s1', 'Acme']], commit: false);

        $this->assertSame(MigrationBatch::STATUS_FAILED, $batch->status);
        $this->assertStringContainsString('Contract number', $batch->error_message);
    }

    // ---- Company / Individual ------------------------------------------

    public function test_contacts_become_companies_and_their_people_become_contacts(): void
    {
        $this->importOdooContacts();

        $acme = $this->acme();
        $this->assertSame(CompanyIndividual::TYPE_COMPANY, $acme->customer_type);
        $this->assertTrue($acme->is_customer);
        $this->assertFalse($acme->is_supplier);
        $this->assertSame(30, $acme->payment_terms_days);
        $this->assertFalse($acme->pdpa_consent_given, 'PDPA consent is never assumed by migration');
        $this->assertSame($acme->id, Contact::where('name', 'Jane Tan')->firstOrFail()->customer_id);

        $bolt = CompanyIndividual::where('name', 'BOLT SUPPLIES')->firstOrFail();
        $this->assertTrue($bolt->is_supplier);
        $this->assertNull($bolt->payment_terms_days, 'a payment term with no day count is left blank');
    }

    public function test_a_second_upload_skips_what_is_already_imported(): void
    {
        $this->importOdooContacts();
        $this->acme()->update(['name' => 'Acme (renamed here)']); // stored as ACME (RENAMED HERE)

        $batch = $this->migrate('odoo', 'company_individuals', [
            ['id', 'name', 'is_company'],
            ['__export__.res_partner_1', 'Acme Pte Ltd', '1'],
            ['__export__.res_partner_9', 'New Co', '1'],
        ]);

        $this->assertSame(['already_imported', 'created'], $this->outcomes($batch));
        $this->assertTrue(CompanyIndividual::where('name', 'ACME (RENAMED HERE)')->exists(), 'never overwritten');
    }

    public function test_same_uen_links_to_the_existing_record_across_systems(): void
    {
        $this->importOdooContacts();

        $batch = $this->migrate('zsoft', 'company_individuals', [
            ['CUST_CODE', 'CUST_NAME', 'ROC_NO'],
            ['Z001', 'ACME PTE. LTD.', '201912345K'],
            ['Z002', 'Delta Engineering', ''],
        ]);

        $this->assertSame(['linked', 'created'], $this->outcomes($batch), json_encode($batch->report));
        $this->assertSame(1, $batch->rows_linked);
        $this->assertSame(3, CompanyIndividual::count(), 'Acme is not duplicated');
        $this->assertSame('ACME PTE LTD', $this->acme()->name, 'the existing record is not overwritten');

        // A ZSOFT past invoice for Z001 lands on the ODOO-imported Acme.
        $this->migrate('zsoft', 'invoices', [
            ['INV_NO', 'CUST_CODE', 'DATE', 'AMOUNT', 'GST', 'TOTAL'],
            ['ZINV-0001', 'Z001', '15/03/2019', '100.00', '7.00', '107.00'],
        ]);
        $this->assertSame($this->acme()->id, Invoice::where('invoice_number', 'ZINV-0001')->value('customer_id'));
    }

    public function test_a_name_only_match_waits_for_link_or_create_new(): void
    {
        $this->importOdooContacts();
        $rows = [['CUST_CODE', 'CUST_NAME'], ['Z001', 'Acme Pte. Ltd.'], ['Z002', 'bolt supplies']];

        $batch = $this->migrate('zsoft', 'company_individuals', $rows);
        $this->assertSame(['needs_decision', 'needs_decision'], $this->outcomes($batch));
        $this->assertSame($this->acme()->id, $batch->report[0]['candidates'][0]['id']);
        $this->assertSame(2, CompanyIndividual::count());

        $batch = $this->migrate('zsoft', 'company_individuals', $rows, decisions: [
            'Z001' => 'link:'.$this->acme()->id,
            'Z002' => 'new',
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $this->assertSame(['linked', 'created'], $this->outcomes($batch));
        $this->assertSame(3, CompanyIndividual::count());
    }

    // ---- documents -------------------------------------------------------

    public function test_subscriptions_become_contracts_keeping_the_old_number(): void
    {
        $this->importOdooContacts();

        $batch = $this->migrate('odoo', 'contracts', [
            ['id', 'name', 'partner_id/id', 'subscription_state', 'start_date', 'end_date', 'recurring_total', 'contracted_hours', 'consumed_hours', 'contract_kind'],
            ['so_10', 'SUB/2025/0001', '__export__.res_partner_1', '3_progress', '2025-07-01', '2026-06-30', '4800.00', '40', '12.5', ''],
            ['so_11', 'SUB/2025/0002', '__export__.res_partner_1', '6_churn', '01/01/2024', '', '1200', '', '', ''],
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));

        $support = Contract::where('contract_number', 'SUB/2025/0001')->firstOrFail();
        $this->assertSame(Contract::KIND_SERVICE_SUPPORT, $support->contract_kind);
        $this->assertSame(Contract::STATUS_ACTIVE, $support->status);
        $this->assertSame(2400, $support->contracted_minutes);
        $this->assertSame(750, $support->consumed_minutes);

        $annual = Contract::where('contract_number', 'SUB/2025/0002')->firstOrFail();
        $this->assertSame(Contract::KIND_ANNUAL, $annual->contract_kind);
        $this->assertSame(Contract::STATUS_EXPIRED, $annual->status);
        $this->assertSame('2024-12-31', $annual->end_date->toDateString());
        $this->assertSame(0, Invoice::count(), 'no annual invoice is issued for a migrated contract');
    }

    public function test_a_service_support_contract_under_ten_hours_fails(): void
    {
        $this->importOdooContacts();

        $batch = $this->migrate('odoo', 'contracts', [
            ['id', 'name', 'partner_id', 'stage_id', 'start_date', 'recurring_total', 'contracted_hours'],
            ['so_10', 'SUB/1', 'Acme Pte Ltd', 'In Progress', '2025-07-01', '500', '5'],
        ], commit: false);

        $this->assertSame('failed', $batch->report[0]['outcome']);
        $this->assertStringContainsString('at least 10', $batch->report[0]['message']);
    }

    public function test_quotations_import_with_their_lines_and_old_figures(): void
    {
        $this->importOdooContacts();
        $header = ['id', 'name', 'partner_id', 'date_order', 'state', 'amount_untaxed', 'amount_tax', 'amount_total', 'order_line/name', 'order_line/product_uom_qty', 'order_line/product_uom', 'order_line/price_unit', 'order_line/price_subtotal', 'order_line/display_type', 'tax_code'];
        $s00012 = [
            ['so_1', 'S00012', 'Acme Pte Ltd, Jane Tan', '2025-03-04 10:15:00', 'sale', '1100.00', '99.00', '1199.00', 'Support hours', '10', 'Hours', '100', '1000.00', '', ''],
            ['', '', '', '', '', '', '', '', 'Setup', '', '', '', '', 'line_section', ''],
            ['', '', '', '', '', '', '', '', 'Setup fee', '1', 'Units', '100', '100.00', '', ''],
        ];

        $batch = $this->migrate('odoo', 'quotations', [$header, ...$s00012,
            ['so_2', 'S00013', 'Acme Pte Ltd', '2025-03-05', 'draft', '500', '0', '500', 'Export service', '1', 'Units', '500', '500', '', ''],
        ]);
        $this->assertSame(['created', 'failed'], $this->outcomes($batch));
        $this->assertStringContainsString('Tax code', $batch->report[1]['message']);

        $batch = $this->migrate('odoo', 'quotations', [$header, ...$s00012,
            ['so_2', 'S00013', 'Acme Pte Ltd', '2025-03-05', 'draft', '500', '0', '500', 'Export service', '1', 'Units', '500', '500', '', 'zr'],
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));

        $quotation = Quotation::where('quotation_number', 'S00012')->firstOrFail();
        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->status);
        $this->assertSame('9.00', $quotation->gst_rate);
        $this->assertSame(['Setup fee', 'Support hours'], $quotation->lines->pluck('description')->sort()->values()->all());
        $this->assertSame(0, Contract::count(), 'an accepted quotation creates no contract on import');
    }

    private function importOdooInvoices(): MigrationBatch
    {
        return $this->migrate('odoo', 'invoices', [
            ['id', 'name', 'partner_id/id', 'move_type', 'state', 'invoice_date', 'invoice_date_due', 'amount_untaxed', 'amount_tax', 'amount_total', 'amount_residual'],
            ['m1', 'INV/2025/00001', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-01-15', '2025-02-14', '1000.00', '90.00', '1090.00', '0.00'],
            ['m2', 'INV/2025/00002', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-02-15', '2025-03-17', '2000.00', '180.00', '2180.00', '1180.00'],
            ['m3', 'INV/2025/00003', '__export__.res_partner_1', 'out_invoice', 'draft', '', '', '10', '0.90', '10.90', '10.90'],
            ['m4', 'RINV/2025/00001', '__export__.res_partner_1', 'out_refund', 'posted', '2025-02-20', '', '100', '9', '109', '0'],
        ]);
    }

    public function test_invoices_are_history_carrying_the_old_paid_amount_and_posting_nothing(): void
    {
        $this->importOdooContacts();
        $batch = $this->importOdooInvoices();

        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $this->assertSame(['created', 'created', 'skipped', 'skipped'], $this->outcomes($batch));
        $this->assertSame(Invoice::STATUS_PAID, Invoice::where('invoice_number', 'INV/2025/00001')->value('status'));
        $partial = Invoice::where('invoice_number', 'INV/2025/00002')->firstOrFail();
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $partial->status);
        $this->assertSame('1180.00', $partial->outstandingSgd()->toString());
        $this->assertNotNull($partial->migrated_at);
        $this->assertSame(0, JournalEntry::count(), 'history only: nothing posts to the General Ledger');

        // A receipt recorded after cut-over settles it without losing the old payments.
        $payment = Payment::create([
            'company_id' => $this->company->id, 'customer_id' => $this->acme()->id, 'voucher_number' => 'RV-2026-0001',
            'payment_date' => '2026-01-10', 'amount_sgd' => '1180.00',
        ]);
        AccountsReceivableService::allocatePayment($payment, $partial, Money::of('1180.00'));
        $this->assertSame('2180.00', $partial->fresh()->amount_paid_sgd);
        $this->assertSame(Invoice::STATUS_PAID, $partial->fresh()->status);
    }

    public function test_zsoft_past_invoices_without_an_amount_due_are_fully_paid_history(): void
    {
        $this->importOdooContacts();
        $batch = $this->migrate('zsoft', 'invoices', [
            ['INV_NO', 'CUST_NAME', 'DATE', 'AMOUNT', 'GST', 'TOTAL'],
            ['ZINV-0001', 'Bolt Supplies', '15/03/2019', '100.00', '7.00', '107.00'],
        ]);

        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $invoice = Invoice::where('invoice_number', 'ZINV-0001')->firstOrFail();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('7.00', $invoice->gst_rate, 'the rate actually charged in 2019, not today\'s');
        $this->assertSame('2019-03-15', $invoice->issued_at->toDateString());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_receipts_are_history_fully_applied_and_never_banked_again(): void
    {
        $this->importOdooContacts();
        $batch = $this->migrate('odoo', 'receipts', [
            ['id', 'name', 'partner_id/id', 'payment_type', 'partner_type', 'state', 'date', 'amount', 'ref', 'journal_id'],
            ['p1', 'PBNK1/2025/00001', '__export__.res_partner_1', 'inbound', 'customer', 'posted', '2025-02-10', '1090.00', 'INV/2025/00001', 'Bank'],
            ['p2', 'PBNK1/2025/00002', '__export__.res_partner_3', 'outbound', 'supplier', 'posted', '2025-02-11', '50.00', '', 'Bank'],
        ]);
        $this->assertSame(['created', 'skipped'], $this->outcomes($batch), json_encode($batch->report));

        $receipt = Payment::where('voucher_number', 'PBNK1/2025/00001')->firstOrFail();
        $this->assertSame('0.00', $receipt->unallocatedSgd()->toString());
        $receipt->update(['bank_account_id' => BankAccount::factory()->for($this->company)->create()->id]);
        $this->expectException(PostingError::class);
        Posting::bankReceipt($receipt->fresh(), $this->owner->id);
    }

    public function test_odoo_timesheets_file_under_one_closed_job_order_and_unknown_staff_become_inactive_users(): void
    {
        $this->importOdooContacts();
        $engineer = User::factory()->for($this->company)->create(['full_name' => 'Nico Lim']);

        $batch = $this->migrate('odoo', 'service_records', [
            ['id', 'date', 'employee_id', 'name', 'unit_amount', 'project_id', 'task_id', 'partner_id/id'],
            ['l1', '2025-08-01', 'Nico Lim', 'Server patching', '1.5', 'Support', 'Acme servers', '__export__.res_partner_1'],
            ['l2', '2025-08-02', 'nico lim', 'Follow-up', '0.5', 'Support', 'Acme servers', '__export__.res_partner_1'],
            ['l3', '2025-08-03', 'Somebody Gone', 'Old job', '1', 'Support', 'Acme servers', '__export__.res_partner_1'],
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));

        $this->assertSame(1, JobOrder::count());
        $this->assertSame(JobOrder::STATUS_CLOSED, JobOrder::firstOrFail()->status);
        $records = ServiceRecord::orderBy('work_date')->get();
        $this->assertSame([90, 30, 60], $records->pluck('rounded_minutes')->all(), 'old hours kept, not re-rounded');
        $this->assertTrue($records->every(fn ($r) => $r->status === ServiceRecord::STATUS_APPROVED && $r->outcome === ServiceRecord::OUTCOME_NOT_HOUR_METERED));
        $this->assertSame($engineer->id, $records[0]->employee_user_id);

        $gone = User::where('full_name', 'Somebody Gone')->firstOrFail();
        $this->assertFalse($gone->is_active, 'a former employee arrives as an inactive user');
        $this->assertStringEndsWith('@migrated.invalid', $gone->email);
        $this->assertSame($gone->id, $records[2]->employee_user_id);
    }

    public function test_zsoft_job_orders_and_service_records_keep_their_numbers(): void
    {
        $this->importOdooContacts();
        $this->migrate('zsoft', 'contracts', [
            ['CONTRACT_NO', 'CUST_NAME', 'STATUS', 'START DATE', 'EXPIRY DATE', 'CONTRACT VALUE', 'HOURS', 'HOURS USED'],
            ['ZC-001', 'Acme Pte Ltd', 'Active', '01/07/2025', '30/06/2026', '3000', '20', '4'],
        ]);
        $batch = $this->migrate('zsoft', 'job_orders', [
            ['JO_NO', 'CUST_NAME', 'SUBJECT', 'STATUS', 'CONTRACT NO', 'ENGINEER', 'DATE'],
            ['ZJ-1001', 'Acme Pte Ltd', 'Printer offline', 'Closed', 'ZC-001', 'Former Tech', '02/08/2025'],
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $jobOrder = JobOrder::where('job_order_number', 'ZJ-1001')->firstOrFail();
        $this->assertSame(JobOrder::STATUS_CLOSED, $jobOrder->status);
        $this->assertSame(Contract::where('contract_number', 'ZC-001')->value('id'), $jobOrder->contract_id);

        $batch = $this->migrate('zsoft', 'service_records', [
            ['SR_NO', 'JO_NO', 'SERVICE DATE', 'ENGINEER', 'HOURS', 'WORK DONE'],
            ['ZSR-5001', 'ZJ-1001', '02/08/2025', 'Former Tech', '2', 'Replaced fuser'],
        ]);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $record = ServiceRecord::where('service_record_number', 'ZSR-5001')->firstOrFail();
        $this->assertSame($jobOrder->id, $record->job_order_id);
        $this->assertSame(1, User::where('full_name', 'Former Tech')->count(), 'the same former employee is created once');
        $this->assertSame(240, Contract::where('contract_number', 'ZC-001')->value('consumed_minutes'), 'hours used carried; not deducted again');
    }

    public function test_a_cut_off_date_leaves_out_closed_transactions_before_it_and_brings_open_ones(): void
    {
        $this->importOdooContacts();
        $batch = $this->upload('odoo', 'invoices', [
            ['id', 'name', 'partner_id/id', 'move_type', 'state', 'invoice_date', 'invoice_date_due', 'amount_untaxed', 'amount_tax', 'amount_total', 'amount_residual'],
            ['m1', 'INV/2025/00001', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-01-15', '', '1000.00', '90.00', '1090.00', '0.00'],
            ['m2', 'INV/2025/00002', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-01-20', '', '2000.00', '180.00', '2180.00', '1180.00'],
            ['m5', 'INV/2025/00005', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-03-01', '', '100.00', '9.00', '109.00', '0.00'],
        ]);
        $batch = MigrationBatches::startDryRun($batch, $this->owner, '2025-02-01');
        $this->assertSame('2025-02-01', $batch->cutoff_date->toDateString());
        $this->assertSame(['skipped', 'created', 'created'], $this->outcomes($batch), json_encode($batch->report));
        $this->assertStringContainsString('before the cut-off date 01/02/2025', $batch->report[0]['message']);

        MigrationMappings::signOff(MigrationMappings::get($this->company->id, 'odoo', 'invoices'), $this->owner);
        $batch = MigrationBatches::startImport($batch->fresh(), $this->owner);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $this->assertSame(['INV/2025/00002', 'INV/2025/00005'], Invoice::orderBy('invoice_number')->pluck('invoice_number')->all(),
            'the paid invoice before the cut-off is left out; the unpaid one comes across whatever its date');

        // Job orders: closed before the cut-off left out, still open brought in.
        $this->migrate('zsoft', 'contracts', [
            ['CONTRACT_NO', 'CUST_NAME', 'STATUS', 'START DATE', 'EXPIRY DATE', 'CONTRACT VALUE', 'HOURS', 'HOURS USED'],
            ['ZC-001', 'Acme Pte Ltd', 'Active', '01/07/2024', '30/06/2026', '3000', '20', '4'],
        ]);
        $jobs = $this->upload('zsoft', 'job_orders', [
            ['JO_NO', 'CUST_NAME', 'SUBJECT', 'STATUS', 'CONTRACT NO', 'ENGINEER', 'DATE'],
            ['ZJ-1', 'Acme Pte Ltd', 'Old and done', 'Closed', 'ZC-001', 'Former Tech', '02/08/2024'],
            ['ZJ-2', 'Acme Pte Ltd', 'Old and still open', 'Open', 'ZC-001', '', '03/08/2024'],
        ]);
        $jobs = MigrationBatches::startDryRun($jobs, $this->owner, '2025-01-01');
        $this->assertSame(['skipped', 'created'], $this->outcomes($jobs), json_encode($jobs->report));

        // Customers, contacts and contracts come across whole: no cut-off is kept for them.
        $parties = $this->upload('odoo', 'company_individuals', [
            ['id', 'name', 'is_company', 'email', 'parent_id/id', 'customer_rank', 'supplier_rank', 'property_payment_term_id', 'ref', 'company_registry'],
            ['__export__.res_partner_9', 'Zed Pte Ltd', '1', '', '', '1', '0', '', '', ''],
        ]);
        $this->assertNull(MigrationBatches::startDryRun($parties, $this->owner, '2030-01-01')->cutoff_date);
    }

    public function test_excel_files_are_read_like_csv(): void
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['External ID', 'Name', 'Is a Company'], ['p1', 'Acme Pte Ltd', true]]);
        $path = tempnam(sys_get_temp_dir(), 'mig').'.xlsx';
        (new Xlsx($sheet))->save($path);

        $batch = MigrationBatches::upload($this->company, 'odoo', 'company_individuals', $path, $this->owner);
        MigrationBatches::startDryRun($batch, $this->owner);
        MigrationMappings::signOff(MigrationMappings::get($this->company->id, 'odoo', 'company_individuals'), $this->owner);
        $batch = MigrationBatches::startImport($batch->fresh(), $this->owner);

        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $batch->status, json_encode($batch->report));
        $this->assertSame(CompanyIndividual::TYPE_COMPANY, $this->acme()->customer_type);
    }

    // ---- roll back -------------------------------------------------------

    public function test_roll_back_removes_what_the_batch_created_and_allows_a_re_import(): void
    {
        $this->importOdooContacts();
        $batch = $this->importOdooInvoices();

        $result = MigrationRollback::run($batch->fresh(), $this->owner, 'Wrong Internal Company');

        $this->assertTrue($result['rolled_back']);
        $this->assertSame(2, $result['removed']);
        $this->assertSame(0, Invoice::count());
        $batch->refresh();
        $this->assertSame(MigrationBatch::STATUS_ROLLED_BACK, $batch->status);
        $this->assertSame('Wrong Internal Company', $batch->rollback_reason);
        $this->assertCount(2, $batch->rollback_report['removed'], 'a snapshot of every removed record is kept');
        $this->assertSame(2, MigrationRecordMap::where('batch_id', $batch->id)->whereNotNull('rolled_back_at')->count());
        $this->assertTrue(AuditLogEntry::where('entity_id', $batch->id)->where('action', 'data_migration_rolled_back')->exists());

        $again = $this->importOdooInvoices();
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $again->status, json_encode($again->report));
        $this->assertSame(2, Invoice::count());
    }

    public function test_roll_back_refuses_and_removes_nothing_when_a_record_is_in_use(): void
    {
        $this->importOdooContacts();
        $contacts = MigrationBatch::where('entity', 'company_individuals')->firstOrFail();
        $this->importOdooInvoices();

        $result = MigrationRollback::run($contacts, $this->owner, 'Try');

        $this->assertFalse($result['rolled_back']);
        $this->assertSame(2, CompanyIndividual::count(), 'nothing removed');
        $this->assertTrue(CompanyIndividual::where('name', 'ACME PTE LTD')->exists());
        $this->assertTrue(Contact::where('name', 'Jane Tan')->exists(), 'not even the records that were free');
        $this->assertStringContainsString('Sales Invoices', collect($result['blockers'])->firstWhere('record', 'ACME PTE LTD')['reason']);
        $this->assertSame(MigrationBatch::STATUS_SUCCEEDED, $contacts->fresh()->status);
    }

    public function test_roll_back_refuses_a_record_edited_since_the_import(): void
    {
        $this->importOdooContacts();
        $batch = MigrationBatch::where('entity', 'company_individuals')->firstOrFail();
        // (Stamped explicitly: inside the test's own transaction the
        // database clock stands still.)
        Audit::record('company_individual', CompanyIndividual::where('name', 'BOLT SUPPLIES')->value('id'), 'updated', $this->owner->id)
            ->forceFill(['at' => now()->addMinute()])->save();

        $result = MigrationRollback::run($batch, $this->owner, 'Try');

        $this->assertFalse($result['rolled_back']);
        $this->assertStringContainsString('Changed since the import', $result['blockers'][0]['reason']);
    }

    public function test_roll_back_never_removes_a_record_it_only_linked_to(): void
    {
        $this->importOdooContacts();
        $zsoft = $this->migrate('zsoft', 'company_individuals', [['CUST_CODE', 'CUST_NAME', 'ROC_NO'], ['Z001', 'Acme', '201912345K']]);

        $result = MigrationRollback::run($zsoft->fresh(), $this->owner, 'Undo ZSOFT');

        $this->assertTrue($result['rolled_back']);
        $this->assertTrue(CompanyIndividual::where('name', 'ACME PTE LTD')->exists());

        // ...and the ODOO batch can't remove Acme while ZSOFT links to it.
        $this->migrate('zsoft', 'company_individuals', [['CUST_CODE', 'CUST_NAME', 'ROC_NO'], ['Z001', 'Acme', '201912345K']]);
        $odoo = MigrationBatch::where('source', 'odoo')->where('entity', 'company_individuals')->firstOrFail();
        $refused = MigrationRollback::run($odoo, $this->owner, 'Try');
        $this->assertFalse($refused['rolled_back']);
        $this->assertStringContainsString('Linked to by ZSOFT', collect($refused['blockers'])->firstWhere('record', 'ACME PTE LTD')['reason']);
    }
}
