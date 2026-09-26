<?php

namespace App\Services\DataMigration\Importers;

use App\Models\Payment;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\RowSkipped;
use App\Services\DataMigration\SourceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ODOO customer payments -> Receipt Voucher, keeping the old number.
 * HISTORY ONLY: no General Ledger posting, never enters the bank book
 * (Posting::bankReceipt() refuses it), and allocates nothing -- what it
 * settled is already in each migrated invoice's amount due. The whole
 * receipt is marked applied before cut-over
 * (pre_migration_allocated_sgd), so it does not reappear here as
 * unapplied credit; any genuinely unapplied credit belongs in the
 * opening balances.
 *
 * Draft and cancelled payments, and outgoing/supplier payments, are
 * skipped.
 */
class ReceiptsImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'receipts';
    }

    public function label(): string
    {
        return 'Receipts (history)';
    }

    public function fields(): array
    {
        return self::sourceIdField('If left unmapped, the receipt number is used.') + [
            'name' => ['label' => 'Receipt number', 'required' => true, 'aliases' => ['name', 'number', 'receipt no', 'receipt number']],
        ] + self::partyFields() + [
            'date' => ['label' => 'Payment date', 'required' => true, 'aliases' => ['date', 'payment date']],
            'amount' => ['label' => 'Amount (SGD)', 'required' => true, 'aliases' => ['amount', 'amount_company_currency_signed']],
            'ref' => ['label' => 'Reference', 'aliases' => ['ref', 'memo', 'payment_reference', 'communication', 'reference']],
            'journal_id' => ['label' => 'Journal / bank', 'aliases' => ['journal_id', 'journal', 'bank']],
            'payment_type' => ['label' => 'Direction', 'aliases' => ['payment_type', 'payment type'], 'hint' => 'Outgoing payments are skipped.'],
            'partner_type' => ['label' => 'Party type', 'aliases' => ['partner_type', 'partner type'], 'hint' => 'Supplier payments are skipped.'],
            'state' => ['label' => 'Status', 'aliases' => ['state', 'status'], 'hint' => 'Only posted receipts are imported.'],
        ] + self::currencyField();
    }

    public function targetType(Model $model): string
    {
        return 'payment';
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
            throw new RowFailed('Receipt number is required.');
        }

        $direction = mb_strtolower($row->get('payment_type') ?? 'inbound');
        if (! in_array($direction, ['inbound', 'receive', 'receive money', 'in'], true)) {
            throw new RowSkipped("{$number} is an outgoing payment, not a receipt.");
        }
        $partnerType = mb_strtolower($row->get('partner_type') ?? 'customer');
        if ($partnerType !== 'customer') {
            throw new RowSkipped("{$number} is a {$partnerType} payment, not a receipt.");
        }
        $state = mb_strtolower($row->get('state') ?? 'posted');
        if (! in_array($state, ['posted', 'paid', 'in_process', 'reconciled', 'sent'], true)) {
            throw new RowSkipped("{$number} is {$state} -- only posted receipts are migrated.");
        }
        // History only, never open: before the cut-off date a receipt is left out.
        $ctx->skipBeforeCutoff($ctx->date($row->get('date'), 'Payment date'), false, "Receipt {$number}");

        $ctx->requireSgd($row);
        $ctx->requireUnusedNumber(Payment::class, 'voucher_number', $number);
        $customer = $ctx->customer($row);
        $amount = $ctx->money($row->get('amount'), 'Amount');
        if ($amount->toFloat() <= 0) {
            throw new RowFailed('Receipt amount must be greater than zero.');
        }

        $journal = $row->get('journal_id');

        return Payment::create([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'voucher_number' => $number,
            'payment_date' => $ctx->date($row->get('date'), 'Payment date')->toDateString(),
            'amount_sgd' => $amount->toString(),
            'method' => Payment::METHOD_OTHER,
            'reference' => mb_substr((string) $row->get('ref'), 0, 200) ?: null,
            'notes' => mb_substr("Migrated from {$ctx->sourceLabel()}".($journal ? " (journal: {$journal})" : '').'.', 0, 500),
            'pre_migration_allocated_sgd' => $amount->toString(),
            'migrated_at' => Carbon::now(),
        ]);
    }

    public function describe(Model $model): string
    {
        return "Receipt {$model->voucher_number}, SGD {$model->amount_sgd}";
    }
}
