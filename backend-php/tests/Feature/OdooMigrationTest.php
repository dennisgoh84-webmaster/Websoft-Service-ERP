<?php

namespace Tests\Feature;

use App\Exceptions\PostingError;
use App\Models\Account;
use App\Models\AuditLogEntry;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\JournalEntry;
use App\Models\OdooImportRun;
use App\Models\OdooRecordMap;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\ServiceRecord;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\OdooMigration\OdooImporter;
use App\Services\Posting;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The Odoo migration program (docs/odoo-migration.md): one import per
 * Odoo record type, dry run by default, all-or-nothing on commit,
 * re-runnable through odoo_record_map, and -- for invoices and
 * receipts -- history only, posting nothing to the General Ledger.
 */
class OdooMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        foreach ([['SR', '9.00'], ['ZR', '0.00'], ['OS', '0.00']] as [$code, $rate]) {
            TaxCode::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $code, 'rate_percent' => $rate]);
        }
    }

    /** @param  array<int, array<int, string>>  $rows  header first */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'odoo').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function import(string $entity, array $rows, bool $commit = true, array $options = []): OdooImportRun
    {
        return OdooImporter::run($this->company, $entity, $this->csv($rows), $commit, null, $options);
    }

    /** @return array<int, string> */
    private function outcomes(OdooImportRun $run): array
    {
        return array_column($run->report, 'outcome');
    }

    private function importContacts(): void
    {
        $run = $this->import('contacts', [
            ['id', 'name', 'is_company', 'email', 'parent_id/id', 'customer_rank', 'supplier_rank', 'property_payment_term_id', 'ref'],
            ['__export__.res_partner_2', 'Jane Tan', '0', 'jane@acme.test', '__export__.res_partner_1', '0', '0', '', ''],
            ['__export__.res_partner_1', 'Acme Pte Ltd', '1', 'ap@acme.test', '', '3', '0', '30 Days', 'C0001'],
            ['__export__.res_partner_3', 'Bolt Supplies', '1', '', '', '0', '2', 'End of Following Month', ''],
        ]);
        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status, json_encode($run->report));
    }

    private function acme(): CompanyIndividual
    {
        return CompanyIndividual::where('name', 'Acme Pte Ltd')->firstOrFail();
    }

    public function test_dry_run_reports_everything_and_writes_nothing(): void
    {
        $run = $this->import('contacts', [
            ['id', 'name', 'is_company'],
            ['__export__.res_partner_1', 'Acme Pte Ltd', '1'],
        ], commit: false);

        $this->assertSame(OdooImportRun::STATUS_DRY_RUN, $run->status);
        $this->assertSame(1, $run->rows_created);
        $this->assertSame(0, CompanyIndividual::count());
        $this->assertSame(0, OdooRecordMap::count());
        // The run itself and its audit entry are kept.
        $this->assertSame(1, OdooImportRun::count());
        $this->assertTrue(AuditLogEntry::where('entity_id', $run->id)->where('action', 'odoo_import_dry_run')->exists());
    }

    public function test_contacts_become_companies_and_their_people_become_contacts(): void
    {
        $this->importContacts();

        $acme = $this->acme();
        $this->assertSame(CompanyIndividual::TYPE_COMPANY, $acme->customer_type);
        $this->assertTrue($acme->is_customer);
        $this->assertFalse($acme->is_supplier);
        $this->assertSame(30, $acme->payment_terms_days);
        $this->assertSame('C0001', $acme->legacy_customer_code);
        $this->assertFalse($acme->pdpa_consent_given, 'PDPA consent is never assumed by migration');

        // The child row came first in the file but still landed under its parent.
        $jane = Contact::where('name', 'Jane Tan')->firstOrFail();
        $this->assertSame($acme->id, $jane->customer_id);
        $this->assertSame(2, CompanyIndividual::count());

        $bolt = CompanyIndividual::where('name', 'Bolt Supplies')->firstOrFail();
        $this->assertTrue($bolt->is_supplier);
        $this->assertFalse($bolt->is_customer);
        $this->assertNull($bolt->payment_terms_days, 'a payment term with no day count is left blank, not approximated');
    }

    public function test_a_rerun_skips_what_it_already_imported(): void
    {
        $this->importContacts();
        $this->acme()->update(['name' => 'Acme (renamed here)']);

        $run = $this->import('contacts', [
            ['id', 'name', 'is_company'],
            ['__export__.res_partner_1', 'Acme Pte Ltd', '1'],
            ['__export__.res_partner_9', 'New Co', '1'],
        ]);

        $this->assertSame(['already_imported', 'created'], $this->outcomes($run));
        $this->assertSame('Acme (renamed here)', CompanyIndividual::find($this->acme_id())->name, 'an imported record is never overwritten');
    }

    private function acme_id(): string
    {
        return OdooRecordMap::where('odoo_ref', '__export__.res_partner_1')->value('target_id');
    }

    public function test_one_failed_row_means_a_commit_writes_nothing(): void
    {
        $run = $this->import('contacts', [
            ['id', 'name', 'is_company'],
            ['__export__.res_partner_1', 'Acme Pte Ltd', '1'],
            ['__export__.res_partner_2', '', '1'],
        ]);

        $this->assertSame(OdooImportRun::STATUS_FAILED, $run->status);
        $this->assertSame(['created', 'failed'], $this->outcomes($run));
        $this->assertSame(0, CompanyIndividual::count());
    }

    public function test_a_row_without_an_external_id_fails(): void
    {
        $run = $this->import('contacts', [['name', 'is_company'], ['Acme Pte Ltd', '1']]);

        $this->assertSame('failed', $run->report[0]['outcome']);
        $this->assertStringContainsString('import-compatible export', $run->report[0]['message']);
    }

    public function test_chart_of_accounts_links_existing_codes_and_refuses_unknown_types(): void
    {
        // The company already has the seeded chart (1000 Cash at bank, 4000 ...).
        $before = Account::count();

        $run = $this->import('accounts', [
            ['id', 'code', 'name', 'account_type'],
            ['account.1', '1000', 'Bank', 'asset_cash'],
            ['account.2', '1200', 'Trade Debtors', 'asset_receivable'],
            ['account.3', '4900', 'Other Revenue', 'Income'],
            ['account.4', '9000', 'Memo', 'off_balance'],
        ], commit: false);

        $this->assertSame(['created', 'created', 'created', 'failed'], $this->outcomes($run));

        $run = $this->import('accounts', [
            ['id', 'code', 'name', 'account_type'],
            ['account.1', '1000', 'Bank', 'liability_current'],
            ['account.2', '1200', 'Trade Debtors', 'asset_receivable'],
        ]);
        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame($before + 1, Account::count(), 'code 1000 is linked, not duplicated');
        $this->assertSame('Cash at bank', Account::where('code', '1000')->value('name'));
        $this->assertNotEmpty($run->report[0]['warnings']);
        $this->assertSame(Account::TYPE_ASSET, Account::where('code', '1200')->value('account_type'));
    }

    public function test_subscriptions_become_contracts_keeping_the_odoo_reference(): void
    {
        $this->importContacts();

        $run = $this->import('subscriptions', [
            ['id', 'name', 'partner_id/id', 'subscription_state', 'start_date', 'end_date', 'recurring_total', 'contracted_hours', 'consumed_hours', 'contract_kind'],
            ['sale.order_10', 'SUB/2025/0001', '__export__.res_partner_1', '3_progress', '2025-07-01', '2026-06-30', '4800.00', '40', '12.5', ''],
            ['sale.order_11', 'SUB/2025/0002', '__export__.res_partner_1', '6_churn', '2024-01-01', '', '1200', '', '', ''],
        ]);
        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status, json_encode($run->report));

        $support = Contract::where('contract_number', 'SUB/2025/0001')->firstOrFail();
        $this->assertSame(Contract::KIND_SERVICE_SUPPORT, $support->contract_kind);
        $this->assertSame(Contract::STATUS_ACTIVE, $support->status);
        $this->assertSame(2400, $support->contracted_minutes);
        $this->assertSame(750, $support->consumed_minutes);
        $this->assertSame('4800.00', $support->contract_value_sgd);
        $this->assertSame($this->acme()->id, $support->customer_id);

        $annual = Contract::where('contract_number', 'SUB/2025/0002')->firstOrFail();
        $this->assertSame(Contract::KIND_ANNUAL, $annual->contract_kind);
        $this->assertSame(Contract::STATUS_EXPIRED, $annual->status);
        $this->assertSame('2024-12-31', $annual->end_date->toDateString(), 'open-ended: the standard 12-month term');
        $this->assertNotEmpty($run->report[1]['warnings']);

        $this->assertSame(0, Invoice::count(), 'no annual invoice is issued for a migrated contract');
    }

    public function test_a_service_support_contract_under_ten_hours_fails(): void
    {
        $this->importContacts();

        $run = $this->import('subscriptions', [
            ['id', 'name', 'partner_id', 'stage_id', 'start_date', 'end_date', 'recurring_total', 'contracted_hours'],
            ['sale.order_10', 'SUB/1', 'Acme Pte Ltd', 'In Progress', '2025-07-01', '2026-06-30', '500', '5'],
        ]);

        $this->assertSame('failed', $run->report[0]['outcome']);
        $this->assertStringContainsString('at least 10', $run->report[0]['message']);
    }

    public function test_quotations_import_with_their_lines_and_odoo_figures(): void
    {
        $this->importContacts();
        $header = ['id', 'name', 'partner_id', 'date_order', 'state', 'amount_untaxed', 'amount_tax', 'amount_total', 'order_line/name', 'order_line/product_uom_qty', 'order_line/product_uom', 'order_line/price_unit', 'order_line/price_subtotal', 'order_line/display_type', 'tax_code'];
        $s00012 = [
            ['sale.order_1', 'S00012', 'Acme Pte Ltd, Jane Tan', '2025-03-04 10:15:00', 'sale', '1100.00', '99.00', '1199.00', 'Support hours', '10', 'Hours', '100', '1000.00', '', ''],
            ['', '', '', '', '', '', '', '', 'Setup', '', '', '', '', 'line_section', ''],
            ['', '', '', '', '', '', '', '', 'Setup fee', '1', 'Units', '100', '100.00', '', ''],
        ];

        $run = $this->import('quotations', [
            $header, ...$s00012,
            ['sale.order_2', 'S00013', 'Acme Pte Ltd', '2025-03-05', 'draft', '500', '0', '500', 'Export service', '1', 'Units', '500', '500', '', ''],
        ], commit: false);
        $this->assertSame(['created', 'failed'], $this->outcomes($run), json_encode($run->report));
        $this->assertStringContainsString('tax_code', $run->report[1]['message']);

        // Say which code the zero-GST quotation is, and commit.
        $run = $this->import('quotations', [
            $header, ...$s00012,
            ['sale.order_2', 'S00013', 'Acme Pte Ltd', '2025-03-05', 'draft', '500', '0', '500', 'Export service', '1', 'Units', '500', '500', '', 'zr'],
        ]);
        $this->assertSame(['created', 'created'], $this->outcomes($run), json_encode($run->report));

        $quotation = Quotation::where('quotation_number', 'S00012')->firstOrFail();
        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->status);
        $this->assertSame('9.00', $quotation->gst_rate);
        $this->assertSame('1199.00', $quotation->total_amount_sgd);
        $this->assertSame(['Setup fee', 'Support hours'], $quotation->lines->pluck('description')->sort()->values()->all());
        $this->assertNull($quotation->converted_contract_id, 'an accepted quotation creates no contract on import');
        $this->assertSame(0, Contract::count());

        $this->assertSame('ZR', Quotation::where('quotation_number', 'S00013')->value('tax_code'));
    }

    private function importInvoices(): OdooImportRun
    {
        return $this->import('invoices', [
            ['id', 'name', 'partner_id/id', 'move_type', 'state', 'invoice_date', 'invoice_date_due', 'amount_untaxed', 'amount_tax', 'amount_total', 'amount_residual', 'invoice_origin'],
            ['account.move_1', 'INV/2025/00001', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-01-15', '2025-02-14', '1000.00', '90.00', '1090.00', '0.00', ''],
            ['account.move_2', 'INV/2025/00002', '__export__.res_partner_1', 'out_invoice', 'posted', '2025-02-15', '2025-03-17', '2000.00', '180.00', '2180.00', '1180.00', 'SUB/2025/0001'],
            ['account.move_3', 'INV/2025/00003', '__export__.res_partner_1', 'out_invoice', 'draft', '', '', '10', '0.90', '10.90', '10.90', ''],
            ['account.move_4', 'RINV/2025/00001', '__export__.res_partner_1', 'out_refund', 'posted', '2025-02-20', '', '100', '9', '109', '0', ''],
        ]);
    }

    public function test_invoices_are_history_carrying_odoos_paid_amount_and_posting_nothing(): void
    {
        $this->importContacts();
        $run = $this->importInvoices();

        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status, json_encode($run->report));
        $this->assertSame(['created', 'created', 'skipped', 'skipped'], $this->outcomes($run));

        $paid = Invoice::where('invoice_number', 'INV/2025/00001')->firstOrFail();
        $this->assertSame(Invoice::STATUS_PAID, $paid->status);
        $this->assertSame('2025-01-15', $paid->issued_at->toDateString());
        $this->assertNotNull($paid->odoo_imported_at);

        $partial = Invoice::where('invoice_number', 'INV/2025/00002')->firstOrFail();
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $partial->status);
        $this->assertSame('1000.00', $partial->pre_migration_paid_sgd);
        $this->assertSame('1180.00', $partial->outstandingSgd()->toString());
        $this->assertSame(Invoice::TYPE_SALES, $partial->invoice_type);

        $this->assertSame(0, JournalEntry::count(), 'history only: nothing posts to the General Ledger');
    }

    public function test_a_receipt_recorded_after_cut_over_settles_a_migrated_invoice_without_losing_odoos_payments(): void
    {
        $this->importContacts();
        $this->importInvoices();
        $partial = Invoice::where('invoice_number', 'INV/2025/00002')->firstOrFail();

        $payment = Payment::create([
            'company_id' => $this->company->id, 'customer_id' => $this->acme()->id, 'voucher_number' => 'RV-2026-0001',
            'payment_date' => '2026-01-10', 'amount_sgd' => '1180.00',
        ]);
        AccountsReceivableService::allocatePayment($payment, $partial, Money::of('1180.00'));

        $partial->refresh();
        $this->assertSame('2180.00', $partial->amount_paid_sgd);
        $this->assertSame(Invoice::STATUS_PAID, $partial->status);
    }

    public function test_receipts_are_history_fully_applied_and_never_banked_again(): void
    {
        $this->importContacts();

        $run = $this->import('receipts', [
            ['id', 'name', 'partner_id/id', 'payment_type', 'partner_type', 'state', 'date', 'amount', 'ref', 'journal_id'],
            ['account.payment_1', 'PBNK1/2025/00001', '__export__.res_partner_1', 'inbound', 'customer', 'posted', '2025-02-10', '1090.00', 'INV/2025/00001', 'Bank'],
            ['account.payment_2', 'PBNK1/2025/00002', '__export__.res_partner_3', 'outbound', 'supplier', 'posted', '2025-02-11', '50.00', '', 'Bank'],
        ]);
        $this->assertSame(['created', 'skipped'], $this->outcomes($run), json_encode($run->report));

        $receipt = Payment::where('voucher_number', 'PBNK1/2025/00001')->firstOrFail();
        $this->assertSame('0.00', $receipt->unallocatedSgd()->toString(), 'not re-offered as unapplied customer credit');
        $this->assertSame(0, JournalEntry::count());

        $receipt->update(['bank_account_id' => BankAccount::factory()->for($this->company)->create()->id]);
        $actor = User::factory()->for($this->company)->create();
        $this->expectException(PostingError::class);
        Posting::bankReceipt($receipt->fresh(), $actor->id);
    }

    public function test_timesheets_become_approved_service_records_under_one_closed_job_order_per_task(): void
    {
        $this->importContacts();
        $engineer = User::factory()->for($this->company)->create(['full_name' => 'Nico Lim']);
        $this->import('subscriptions', [
            ['id', 'name', 'partner_id/id', 'subscription_state', 'start_date', 'end_date', 'recurring_total', 'contracted_hours', 'consumed_hours'],
            ['sale.order_10', 'SUB/1', '__export__.res_partner_1', '3_progress', '2025-07-01', '2026-06-30', '4800', '40', '2'],
        ]);

        $run = $this->import('timesheets', [
            ['id', 'date', 'employee_id', 'name', 'unit_amount', 'project_id', 'task_id', 'partner_id/id'],
            ['account.analytic.line_1', '2025-08-01', 'Nico Lim', 'Server patching', '1.5', 'Support', 'Acme servers', '__export__.res_partner_1'],
            ['account.analytic.line_2', '2025-08-02', 'nico lim', 'Follow-up', '0.5', 'Support', 'Acme servers', '__export__.res_partner_1'],
            ['account.analytic.line_3', '2025-08-03', 'Somebody Gone', 'x', '1', 'Support', 'Acme servers', '__export__.res_partner_1'],
        ], commit: false);
        $this->assertSame(['created', 'created', 'failed'], $this->outcomes($run));
        $this->assertStringContainsString('Somebody Gone', $run->report[2]['message']);

        $run = $this->import('timesheets', [
            ['id', 'date', 'employee_id', 'name', 'unit_amount', 'project_id', 'task_id', 'partner_id/id'],
            ['account.analytic.line_1', '2025-08-01', 'Nico Lim', 'Server patching', '1.5', 'Support', 'Acme servers', '__export__.res_partner_1'],
            ['account.analytic.line_2', '2025-08-02', 'nico lim', 'Follow-up', '0.5', 'Support', 'Acme servers', '__export__.res_partner_1'],
        ]);
        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status, json_encode($run->report));

        $this->assertSame(1, JobOrder::count());
        $jobOrder = JobOrder::firstOrFail();
        $this->assertSame(JobOrder::STATUS_CLOSED, $jobOrder->status);
        $this->assertSame('Odoo: Support / Acme servers', $jobOrder->subject);

        $records = ServiceRecord::orderBy('work_date')->get();
        $this->assertSame([90, 30], $records->pluck('rounded_minutes')->all(), 'Odoo hours kept exactly, not re-rounded');
        $this->assertTrue($records->every(fn ($r) => $r->status === ServiceRecord::STATUS_APPROVED
            && $r->outcome === ServiceRecord::OUTCOME_NOT_HOUR_METERED
            && $r->employee_user_id === $engineer->id));
        $this->assertSame(120, Contract::firstOrFail()->consumed_minutes, "the contract keeps Odoo's consumed hours; timesheets don't deduct again");
    }

    public function test_opening_balances_post_one_balanced_voucher_once(): void
    {
        // 1000 and 1100 are in the company's seeded chart.
        Account::create(['company_id' => $this->company->id, 'code' => '3000', 'name' => 'Retained earnings', 'account_type' => Account::TYPE_EQUITY]);
        $rows = [
            ['account', 'debit', 'credit'],
            ['1000 Bank', '5,000.00', ''],
            ['1100 Trade Debtors', '1180.00', '0'],
            ['3000 Retained Earnings', '', '6180.00'],
            ['1000 Bank', '0', '0'],
        ];

        $unbalanced = $this->import('opening_balances', [...array_slice($rows, 0, 3)], options: ['as_at' => '2025-12-31']);
        $this->assertSame(OdooImportRun::STATUS_FAILED, $unbalanced->status);
        $this->assertStringContainsString('does not balance', $unbalanced->report[0]['message']);

        $run = $this->import('opening_balances', $rows, options: ['as_at' => '2025-12-31']);
        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status, json_encode($run->report));
        $entry = JournalEntry::firstOrFail();
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertSame('2025-12-31', $entry->entry_date->toDateString());
        $this->assertSame('6180.00', $entry->totalDebit()->toString());

        $again = $this->import('opening_balances', $rows, options: ['as_at' => '2025-12-31']);
        $this->assertSame(['already_imported'], $this->outcomes($again));
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_excel_exports_are_read_like_csv(): void
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['External ID', 'Name', 'Is a Company'],
            ['__export__.res_partner_1', 'Acme Pte Ltd', true],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'odoo').'.xlsx';
        (new Xlsx($sheet))->save($path);

        $run = OdooImporter::run($this->company, 'contacts', $path, true);

        $this->assertSame(OdooImportRun::STATUS_SUCCEEDED, $run->status, json_encode($run->report));
        $this->assertSame(CompanyIndividual::TYPE_COMPANY, $this->acme()->customer_type);
    }

    public function test_the_artisan_command_dry_runs_by_default(): void
    {
        $path = $this->csv([['id', 'name', 'is_company'], ['__export__.res_partner_1', 'Acme Pte Ltd', '1']]);

        $this->artisan('odoo:import', ['entity' => 'contacts', 'file' => $path, '--company' => $this->company->code])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
        $this->assertSame(0, CompanyIndividual::count());

        $this->artisan('odoo:import', ['entity' => 'contacts', 'file' => $path, '--company' => $this->company->code, '--commit' => true])
            ->expectsOutputToContain('Imported.')
            ->assertExitCode(0);
        $this->assertSame(1, CompanyIndividual::count());

        $this->artisan('odoo:import', ['entity' => 'nonsense', 'file' => $path, '--company' => $this->company->code])
            ->assertExitCode(1);
    }
}
