<?php

namespace App\Services\DataMigration\Importers;

use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\SourceRecord;
use App\Services\DataMigration\SourceRow;
use Illuminate\Database\Eloquent\Model;

/**
 * ODOO Sales Quotations -> Quotation, keeping the old number, with its
 * lines when the export includes them.
 *
 * ODOO writes a quotation with several lines as one row per line: the
 * first carries the quotation's own columns, the rest leave them blank
 * and carry only the line columns. Section and note lines have no
 * price and are left out.
 *
 * Every amount is the old system's own figure -- never recomputed
 * here. History only: an accepted quotation does NOT create a contract
 * on import (the contract arrives through the Contracts module).
 */
class QuotationsImporter extends EntityImporter
{
    private const STATUSES = [
        'draft' => Quotation::STATUS_DRAFT, 'quotation' => Quotation::STATUS_DRAFT,
        'sent' => Quotation::STATUS_SENT, 'quotation sent' => Quotation::STATUS_SENT,
        'sale' => Quotation::STATUS_ACCEPTED, 'sales order' => Quotation::STATUS_ACCEPTED, 'accepted' => Quotation::STATUS_ACCEPTED,
        'done' => Quotation::STATUS_ACCEPTED, 'locked' => Quotation::STATUS_ACCEPTED,
        'cancel' => Quotation::STATUS_REJECTED, 'cancelled' => Quotation::STATUS_REJECTED, 'rejected' => Quotation::STATUS_REJECTED,
        'expired' => Quotation::STATUS_EXPIRED,
    ];

    public function entity(): string
    {
        return 'quotations';
    }

    public function label(): string
    {
        return 'Quotations';
    }

    public function fields(): array
    {
        return self::sourceIdField('If left unmapped, the quotation number is used.') + [
            'name' => ['label' => 'Quotation number', 'required' => true, 'aliases' => ['name', 'order reference', 'number', 'quotation no', 'quotation number']],
        ] + self::partyFields() + [
            'date_order' => ['label' => 'Quotation date', 'required' => true, 'aliases' => ['date_order', 'order date', 'quotation date', 'date']],
            'validity_date' => ['label' => 'Valid until', 'aliases' => ['validity_date', 'expiration', 'expiration date', 'valid until']],
            'state' => ['label' => 'Status', 'required' => true, 'aliases' => ['state', 'status'], 'hint' => 'draft / sent / sale (accepted) / cancel (rejected).'],
            'amount_untaxed' => ['label' => 'Amount before GST (SGD)', 'required' => true, 'aliases' => ['amount_untaxed', 'untaxed amount']],
            'amount_tax' => ['label' => 'GST (SGD)', 'required' => true, 'aliases' => ['amount_tax', 'taxes', 'gst']],
            'amount_total' => ['label' => 'Total (SGD)', 'required' => true, 'aliases' => ['amount_total', 'total']],
            'tax_code' => ['label' => 'Tax code', 'aliases' => ['tax_code', 'tax code'], 'hint' => 'Needed only on documents with no GST: ZR, ES or OS.'],
            'note' => ['label' => 'Notes', 'aliases' => ['note', 'terms and conditions', 'notes']],
            'order_line/name' => ['label' => 'Line: description', 'aliases' => ['order_line/name', 'order lines/description']],
            'order_line/product_id' => ['label' => 'Line: product', 'aliases' => ['order_line/product_id', 'order lines/product']],
            'order_line/product_uom_qty' => ['label' => 'Line: quantity', 'aliases' => ['order_line/product_uom_qty', 'order lines/quantity']],
            'order_line/product_uom' => ['label' => 'Line: unit of measure', 'aliases' => ['order_line/product_uom', 'order lines/unit of measure']],
            'order_line/price_unit' => ['label' => 'Line: unit price', 'aliases' => ['order_line/price_unit', 'order lines/unit price']],
            'order_line/price_subtotal' => ['label' => 'Line: subtotal', 'aliases' => ['order_line/price_subtotal', 'order lines/subtotal']],
            'order_line/display_type' => ['label' => 'Line: section/note marker', 'aliases' => ['order_line/display_type', 'order lines/display type']],
        ] + self::currencyField();
    }

    public function targetType(Model $model): string
    {
        return 'quotation';
    }

    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'name');
    }

    public function records(array $rows): array
    {
        $records = [];
        $current = null;
        foreach ($rows as $row) {
            if ($current !== null && $row->get('id', 'name') === null) {
                $current->lineRows[] = $row;

                continue;
            }
            $current = new SourceRecord($row, [$row]);
            $records[] = $current;
        }

        return $records;
    }

    public function import(SourceRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $ctx->requireSgd($row);

        $number = $row->get('name');
        if ($number === null) {
            throw new RowFailed('Quotation number is required.');
        }
        // Before the cut-off date, only a quotation still awaiting the customer (draft or sent) comes across.
        $mapped = self::STATUSES[mb_strtolower((string) $row->get('state'))] ?? null;
        $ctx->skipBeforeCutoff($ctx->date($row->get('date_order'), 'Quotation date'),
            $mapped === null || in_array($mapped, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true), "Quotation {$number}");
        $ctx->requireUnusedNumber(Quotation::class, 'quotation_number', $number);
        $customer = $ctx->customer($row);

        $oldState = $row->get('state');
        $status = $oldState === null ? null : (self::STATUSES[mb_strtolower($oldState)] ?? null);
        if ($status === null) {
            throw new RowFailed('Status "'.($oldState ?? '').'" has no equivalent quotation status here.');
        }

        $net = $ctx->money($row->get('amount_untaxed'), 'Amount before GST');
        $tax = $ctx->money($row->get('amount_tax'), 'GST');
        $total = $ctx->money($row->get('amount_total'), 'Total');
        [$taxCode, $rate] = $ctx->taxCode($row, $net, $tax);

        $quotation = Quotation::create([
            'company_id' => $ctx->company->id,
            'quotation_number' => $number,
            'customer_id' => $customer->id,
            'quotation_date' => $ctx->date($row->get('date_order'), 'Quotation date')->toDateString(),
            'valid_until' => $ctx->date($row->get('validity_date'), 'Valid until', required: false)?->toDateString(),
            'status' => $status,
            'notes' => $row->get('note'),
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

    private function line(Quotation $quotation, SourceRow $row, ImportContext $ctx): void
    {
        $description = $row->get('order_line/name', 'order_line/product_id');
        if ($description === null || $row->get('order_line/display_type') !== null) {
            return;
        }
        $quantity = $row->get('order_line/product_uom_qty') ?? '1';
        if (! is_numeric($quantity)) {
            throw new RowFailed("Row {$row->number}: line quantity \"{$quantity}\" is not a number.");
        }

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'description' => mb_substr($description, 0, 255),
            'unit_of_measure' => $row->get('order_line/product_uom'),
            'quantity' => $quantity,
            'unit_price_sgd' => $ctx->money($row->get('order_line/price_unit'), "row {$row->number} line unit price")->toString(),
            'line_total_sgd' => $ctx->money($row->get('order_line/price_subtotal'), "row {$row->number} line subtotal")->toString(),
        ]);
    }

    public function describe(Model $model): string
    {
        return "Quotation {$model->quotation_number} ({$model->status}), SGD {$model->total_amount_sgd}";
    }
}
