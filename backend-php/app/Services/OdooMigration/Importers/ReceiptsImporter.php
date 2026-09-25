<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\Payment;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\RowFailed;
use App\Services\OdooMigration\RowSkipped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Odoo customer payments (account.payment, inbound, customer) ->
 * Receipt Voucher (Payment), keeping Odoo's number. HISTORY ONLY: no
 * GL voucher, never enters the bank book (Posting::bankReceipt()
 * refuses it), and allocates nothing -- what it settled is already in
 * each migrated invoice's Odoo amount due. The whole receipt is marked
 * allocated before cut-over (pre_migration_allocated_sgd), so it does
 * not reappear here as unapplied customer credit; any genuinely
 * unapplied Odoo credit is carried in the opening receivables balance.
 *
 * Draft and cancelled payments, and outbound/supplier payments, are
 * skipped.
 */
class ReceiptsImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'receipts';
    }

    public function targetType(Model $model): string
    {
        return 'payment';
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $number = $row->get('name', 'number');
        if ($number === null) {
            throw new RowFailed('A receipt needs its number (name).');
        }

        $direction = mb_strtolower($row->get('payment_type', 'payment type') ?? 'inbound');
        if (! in_array($direction, ['inbound', 'receive', 'receive money'], true)) {
            throw new RowSkipped("{$number} is an outgoing payment, not a customer receipt.");
        }
        $partnerType = mb_strtolower($row->get('partner_type', 'partner type') ?? 'customer');
        if ($partnerType !== 'customer') {
            throw new RowSkipped("{$number} is a {$partnerType} payment, not a customer receipt.");
        }
        $state = mb_strtolower($row->get('state', 'status') ?? 'posted');
        if (! in_array($state, ['posted', 'paid', 'in_process', 'reconciled', 'sent'], true)) {
            throw new RowSkipped("{$number} is {$state} in Odoo -- only posted receipts are migrated.");
        }

        $ctx->requireSgd($row);
        $ctx->requireUnusedNumber(Payment::class, 'voucher_number', $number);
        $customer = $ctx->customer($row, 'partner_id', 'customer', 'partner');
        $amount = $ctx->money($row->get('amount', 'amount_company_currency_signed'), 'amount');
        if ($amount->toFloat() <= 0) {
            throw new RowFailed('Receipt amount must be greater than zero.');
        }

        $journal = $row->get('journal_id', 'journal');

        return Payment::create([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'voucher_number' => $number,
            'payment_date' => $ctx->date($row->get('date', 'payment date'), 'payment date')->toDateString(),
            'amount_sgd' => $amount->toString(),
            'method' => Payment::METHOD_OTHER,
            'reference' => mb_substr((string) $row->get('ref', 'memo', 'payment_reference', 'communication'), 0, 200) ?: null,
            'notes' => mb_substr('Migrated from Odoo'.($journal ? " (journal: {$journal})" : '').'.', 0, 500),
            'pre_migration_allocated_sgd' => $amount->toString(),
            'odoo_imported_at' => Carbon::now(),
        ]);
    }

    public function describe(Model $model): string
    {
        return "Receipt {$model->voucher_number}, SGD {$model->amount_sgd}";
    }
}
