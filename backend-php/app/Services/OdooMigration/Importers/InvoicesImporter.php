<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\Contract;
use App\Models\Invoice;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\RowFailed;
use App\Services\OdooMigration\RowSkipped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Odoo Sales Invoices (account.move, move_type out_invoice) -> Invoice,
 * keeping Odoo's number. HISTORY ONLY (decided 2026-09-25): no General
 * Ledger voucher is posted -- the ledger is carried across by the
 * opening_balances import -- and no commission, stock or contract side
 * effect runs.
 *
 * What Odoo had already been paid (amount_total - amount_residual)
 * becomes the invoice's pre_migration_paid_sgd, so it is still
 * outstanding here by exactly Odoo's residual and a receipt recorded
 * here after cut-over can settle the rest in the ordinary way.
 *
 * Draft and cancelled invoices are skipped, as are credit notes
 * (out_refund), which have no equivalent document here yet.
 */
class InvoicesImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'invoices';
    }

    public function targetType(Model $model): string
    {
        return 'invoice';
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $number = $row->get('name', 'number');
        if ($number === null) {
            throw new RowFailed('An invoice needs its number (name).');
        }

        $moveType = mb_strtolower($row->get('move_type', 'type') ?? 'out_invoice');
        if (in_array($moveType, ['out_refund', 'credit note', 'customer credit note'], true)) {
            throw new RowSkipped("{$number} is a credit note -- not migrated (no credit note document here yet).");
        }
        if (! in_array($moveType, ['out_invoice', 'invoice', 'customer invoice'], true)) {
            throw new RowSkipped("{$number} is a {$moveType}, not a customer invoice.");
        }
        $state = mb_strtolower($row->get('state', 'status') ?? 'posted');
        if (! in_array($state, ['posted', 'open', 'paid'], true)) {
            throw new RowSkipped("{$number} is {$state} in Odoo -- only posted invoices are migrated.");
        }

        $ctx->requireSgd($row);
        $ctx->requireUnusedNumber(Invoice::class, 'invoice_number', $number);
        $customer = $ctx->customer($row, 'partner_id', 'customer', 'partner');

        $net = $ctx->money($row->get('amount_untaxed', 'untaxed amount', 'tax excluded'), 'untaxed amount');
        $tax = $ctx->money($row->get('amount_tax', 'tax', 'taxes'), 'tax amount');
        $total = $ctx->money($row->get('amount_total', 'total'), 'total');
        $residual = $ctx->money($row->get('amount_residual', 'amount due', 'amount_residual_signed'), 'amount due');
        if ($residual->toFloat() < 0 || $residual->toFloat() > $total->toFloat()) {
            throw new RowFailed("Amount due {$residual->toString()} is outside 0..{$total->toString()}.");
        }
        [$taxCode, $rate] = $ctx->taxCode($row, $net, $tax);
        $paid = $total->minus($residual);

        $issuedOn = $ctx->date($row->get('invoice_date', 'invoice/bill date', 'date'), 'invoice date');
        $origin = $row->get('invoice_origin', 'source document');

        $invoice = new Invoice([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'contract_id' => $origin === null ? null
                : Contract::where('company_id', $ctx->company->id)->where('contract_number', $origin)->value('id'),
            'invoice_number' => $number,
            'invoice_type' => Invoice::TYPE_SALES,
            'description' => mb_substr($row->get('ref', 'payment_reference', 'reference', 'narration') ?? "Migrated from Odoo invoice {$number}", 0, 500),
            'amount_sgd' => $net->toString(),
            'tax_code' => $taxCode,
            'gst_rate' => $rate,
            'gst_amount_sgd' => $tax->toString(),
            'total_amount_sgd' => $total->toString(),
            'due_date' => $ctx->date($row->get('invoice_date_due', 'due date'), 'due date', required: false)?->toDateString(),
            'amount_paid_sgd' => $paid->toString(),
            'pre_migration_paid_sgd' => $paid->toString(),
            'status' => match (true) {
                $residual->toFloat() == 0.0 => Invoice::STATUS_PAID,
                $paid->toFloat() == 0.0 => Invoice::STATUS_OUTSTANDING,
                default => Invoice::STATUS_PARTIALLY_PAID,
            },
            'odoo_imported_at' => Carbon::now(),
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
