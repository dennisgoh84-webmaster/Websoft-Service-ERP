<?php

namespace App\Services\DataMigration\Importers;

use App\Models\Contract;
use App\Models\Invoice;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\RowSkipped;
use App\Services\DataMigration\SourceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ODOO Sales Invoices / ZSOFT Past Invoices -> Invoice, keeping the old
 * number. HISTORY ONLY (decided 2026-09-25): nothing posts to the
 * General Ledger -- the ledger starts afresh from a management-accounts
 * opening-balance Journal Voucher keyed in here -- and no commission,
 * stock or contract side effect runs. The invoice sits under its
 * Company / Individual, so it shows on that record and on the
 * Prospect (CRM) screen for the same party.
 *
 * What the old system had already been paid (total - amount due)
 * becomes pre_migration_paid_sgd, so the invoice is outstanding here
 * by exactly the old amount due, and a receipt recorded here after
 * cut-over settles the rest in the ordinary way. With no Amount due
 * column mapped, every invoice is taken as fully paid history.
 *
 * Draft and cancelled invoices are skipped, as are credit notes, which
 * have no equivalent document here yet.
 */
class InvoicesImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Sales Invoices (history)';
    }

    public function fields(): array
    {
        return self::sourceIdField('If left unmapped, the invoice number is used.') + [
            'name' => ['label' => 'Invoice number', 'required' => true, 'aliases' => ['name', 'number', 'invoice no', 'invoice number', 'inv_no']],
        ] + self::partyFields() + [
            'invoice_date' => ['label' => 'Invoice date', 'required' => true, 'aliases' => ['invoice_date', 'invoice/bill date', 'invoice date', 'inv_date', 'date']],
            'invoice_date_due' => ['label' => 'Due date', 'aliases' => ['invoice_date_due', 'due date']],
            'amount_untaxed' => ['label' => 'Amount before GST (SGD)', 'required' => true, 'aliases' => ['amount_untaxed', 'untaxed amount', 'tax excluded', 'amount']],
            'amount_tax' => ['label' => 'GST (SGD)', 'required' => true, 'aliases' => ['amount_tax', 'tax', 'taxes', 'gst']],
            'amount_total' => ['label' => 'Total (SGD)', 'required' => true, 'aliases' => ['amount_total', 'total']],
            'amount_residual' => ['label' => 'Amount due (SGD)', 'aliases' => ['amount_residual', 'amount due', 'balance', 'outstanding'], 'hint' => 'Blank or unmapped = fully paid history.'],
            'tax_code' => ['label' => 'Tax code', 'aliases' => ['tax_code', 'tax code'], 'hint' => 'Needed only on invoices with no GST: ZR, ES or OS.'],
            'ref' => ['label' => 'Description', 'aliases' => ['ref', 'payment_reference', 'reference', 'narration', 'description']],
            'invoice_origin' => ['label' => 'Contract number', 'aliases' => ['invoice_origin', 'source document', 'contract no', 'contract number'], 'hint' => 'Links the invoice to a migrated contract.'],
            'move_type' => ['label' => 'Document type', 'aliases' => ['move_type', 'type'], 'hint' => 'Credit notes (out_refund) are skipped.'],
            'state' => ['label' => 'Status', 'aliases' => ['state', 'status'], 'hint' => 'Only posted invoices are imported; drafts and cancelled are skipped.'],
        ] + self::currencyField();
    }

    public function targetType(Model $model): string
    {
        return 'invoice';
    }

    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'name');
    }

    public function import(SourceRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $number = $row->get('name');
        if ($number === null) {
            throw new RowFailed('Invoice number is required.');
        }

        $moveType = mb_strtolower($row->get('move_type') ?? 'out_invoice');
        if (in_array($moveType, ['out_refund', 'credit note', 'customer credit note', 'cn'], true)) {
            throw new RowSkipped("{$number} is a credit note -- not migrated (no credit note document here yet).");
        }
        if (! in_array($moveType, ['out_invoice', 'invoice', 'customer invoice', 'inv', 'tax invoice'], true)) {
            throw new RowSkipped("{$number} is a {$moveType}, not a sales invoice.");
        }
        $state = mb_strtolower($row->get('state') ?? 'posted');
        if (! in_array($state, ['posted', 'open', 'paid', 'partial', 'outstanding', 'issued', 'confirmed'], true)) {
            throw new RowSkipped("{$number} is {$state} -- only issued invoices are migrated.");
        }
        // Before the cut-off date, only an invoice with money still due comes across.
        $due = $ctx->money($row->get('amount_residual'), 'Amount due', required: false);
        $ctx->skipBeforeCutoff($ctx->date($row->get('invoice_date'), 'Invoice date'), $due !== null && $due->toFloat() > 0, "Invoice {$number}");

        $ctx->requireSgd($row);
        $ctx->requireUnusedNumber(Invoice::class, 'invoice_number', $number);
        $customer = $ctx->customer($row);

        $net = $ctx->money($row->get('amount_untaxed'), 'Amount before GST');
        $tax = $ctx->money($row->get('amount_tax'), 'GST');
        $total = $ctx->money($row->get('amount_total'), 'Total');
        $residual = $ctx->money($row->get('amount_residual'), 'Amount due', required: false) ?? $total->minus($total);
        if ($residual->toFloat() < 0 || $residual->toFloat() > $total->toFloat()) {
            throw new RowFailed("Amount due {$residual->toString()} is outside 0 to {$total->toString()}.");
        }
        [$taxCode, $rate] = $ctx->taxCode($row, $net, $tax);
        $paid = $total->minus($residual);

        $issuedOn = $ctx->date($row->get('invoice_date'), 'Invoice date');
        $origin = $row->get('invoice_origin');

        $invoice = new Invoice([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'contract_id' => $origin === null ? null
                : Contract::where('company_id', $ctx->company->id)->where('contract_number', $origin)->value('id'),
            'invoice_number' => $number,
            'invoice_type' => Invoice::TYPE_SALES,
            'description' => mb_substr($row->get('ref') ?? "Migrated from {$ctx->sourceLabel()} invoice {$number}", 0, 500),
            'amount_sgd' => $net->toString(),
            'tax_code' => $taxCode,
            'gst_rate' => $rate,
            'gst_amount_sgd' => $tax->toString(),
            'total_amount_sgd' => $total->toString(),
            'due_date' => $ctx->date($row->get('invoice_date_due'), 'Due date', required: false)?->toDateString(),
            'amount_paid_sgd' => $paid->toString(),
            'pre_migration_paid_sgd' => $paid->toString(),
            'status' => match (true) {
                $residual->toFloat() == 0.0 => Invoice::STATUS_PAID,
                $paid->toFloat() == 0.0 => Invoice::STATUS_OUTSTANDING,
                default => Invoice::STATUS_PARTIALLY_PAID,
            },
            'migrated_at' => Carbon::now(),
        ]);
        $invoice->issued_at = $issuedOn;
        $invoice->save();

        return $invoice;
    }

    public function describe(Model $model): string
    {
        return "Invoice {$model->invoice_number} ({$model->status}), SGD {$model->total_amount_sgd}";
    }
}
