<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\OdooRow;
use App\Services\OdooMigration\RowFailed;
use Illuminate\Database\Eloquent\Model;

/**
 * Odoo Sales Quotations (sale.order) -> Quotation, keeping Odoo's
 * number, with its lines when the export includes them.
 *
 * Odoo writes a quotation with several lines as one row per line: the
 * first carries the quotation's own columns, the rest leave them blank
 * and carry only the `order_line/...` columns. Section and note lines
 * (`order_line/display_type`) have no price and are left out.
 *
 * Every amount is Odoo's own figure -- line subtotals, untaxed, tax,
 * total -- never recomputed here. History only: an accepted quotation
 * does NOT create a contract on import (the contract, if there is one,
 * arrives with the subscriptions import).
 */
class QuotationsImporter extends EntityImporter
{
    private const STATUSES = [
        'draft' => Quotation::STATUS_DRAFT, 'quotation' => Quotation::STATUS_DRAFT,
        'sent' => Quotation::STATUS_SENT, 'quotation sent' => Quotation::STATUS_SENT,
        'sale' => Quotation::STATUS_ACCEPTED, 'sales order' => Quotation::STATUS_ACCEPTED,
        'done' => Quotation::STATUS_ACCEPTED, 'locked' => Quotation::STATUS_ACCEPTED,
        'cancel' => Quotation::STATUS_REJECTED, 'cancelled' => Quotation::STATUS_REJECTED,
    ];

    public function entity(): string
    {
        return 'quotations';
    }

    public function targetType(Model $model): string
    {
        return 'quotation';
    }

    public function records(array $rows): array
    {
        $records = [];
        $current = null;
        foreach ($rows as $row) {
            $isContinuation = $current !== null && $row->get('id', 'external id', 'name', 'order reference') === null;
            if ($isContinuation) {
                $current->lineRows[] = $row;

                continue;
            }
            $current = new OdooRecord($row, [$row]);
            $records[] = $current;
        }

        return $records;
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $ctx->requireSgd($row);

        $number = $row->get('name', 'order reference', 'number');
        if ($number === null) {
            throw new RowFailed('A quotation needs its number (name).');
        }
        $ctx->requireUnusedNumber(Quotation::class, 'quotation_number', $number);
        $customer = $ctx->customer($row, 'partner_id', 'customer');

        $odooState = $row->get('state', 'status');
        $status = $odooState === null ? null : (self::STATUSES[mb_strtolower($odooState)] ?? null);
        if ($status === null) {
            throw new RowFailed('Odoo state "'.($odooState ?? '').'" has no equivalent quotation status here.');
        }

        $net = $ctx->money($row->get('amount_untaxed', 'untaxed amount'), 'untaxed amount');
        $tax = $ctx->money($row->get('amount_tax', 'taxes'), 'tax amount');
        $total = $ctx->money($row->get('amount_total', 'total'), 'total');
        [$taxCode, $rate] = $ctx->taxCode($row, $net, $tax);

        $quotation = Quotation::create([
            'company_id' => $ctx->company->id,
            'quotation_number' => $number,
            'customer_id' => $customer->id,
            'quotation_date' => $ctx->date($row->get('date_order', 'order date', 'quotation date'), 'order date')->toDateString(),
            'valid_until' => $ctx->date($row->get('validity_date', 'expiration', 'expiration date'), 'expiration', required: false)?->toDateString(),
            'status' => $status,
            'notes' => $row->get('note', 'terms and conditions'),
            'amount_sgd' => $net->toString(),
            'tax_code' => $taxCode,
            'gst_rate' => $rate,
            'gst_amount_sgd' => $tax->toString(),
            'total_amount_sgd' => $total->toString(),
        ]);

        foreach ($record->lineRows as $lineRow) {
            $this->line($quotation, $lineRow, $ctx);
        }

        return $quotation;
    }

    private function line(Quotation $quotation, OdooRow $row, ImportContext $ctx): void
    {
        $description = $row->get('order_line/name', 'order lines/description', 'order_line/product_id', 'order lines/product');
        if ($description === null) {
            return;
        }
        if ($row->get('order_line/display_type', 'order lines/display type') !== null) {
            return;
        }
        $quantity = $row->get('order_line/product_uom_qty', 'order lines/quantity') ?? '1';
        if (! is_numeric($quantity)) {
            throw new RowFailed("Row {$row->number}: line quantity \"{$quantity}\" is not a number.");
        }

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'description' => mb_substr($description, 0, 255),
            'unit_of_measure' => $row->get('order_line/product_uom', 'order lines/unit of measure'),
            'quantity' => $quantity,
            'unit_price_sgd' => $ctx->money($row->get('order_line/price_unit', 'order lines/unit price'), "row {$row->number} unit price")->toString(),
            'line_total_sgd' => $ctx->money($row->get('order_line/price_subtotal', 'order lines/subtotal'), "row {$row->number} line subtotal")->toString(),
        ]);
    }

    public function describe(Model $model): string
    {
        return "Quotation {$model->quotation_number} ({$model->status}), SGD {$model->total_amount_sgd}";
    }
}
