<?php

namespace App\Services\OdooMigration\Importers;

use App\Exceptions\LedgerRuleViolation;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\OdooRow;
use App\Services\OdooMigration\RowFailed;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Odoo's Trial Balance at cut-over -> ONE posted journal voucher dated
 * the cut-over date (`--as-at`). Because migrated invoices and receipts
 * are history only and post nothing, this voucher is how the General
 * Ledger -- receivables, bank, retained earnings, everything -- arrives
 * here, and it is why the Trial Balance here matches Odoo's on day one.
 *
 * Columns: `code` (or `account`, e.g. "1100 Bank", whose leading code
 * is used), and either `debit` + `credit` or a signed `balance`
 * (positive = debit). Every account must already exist (run the
 * accounts import first). The whole file is one record: it balances or
 * nothing is posted, and it can be imported once per cut-over date.
 */
class OpeningBalancesImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'opening_balances';
    }

    public function targetType(Model $model): string
    {
        return 'journal_entry';
    }

    public function records(array $rows): array
    {
        return $rows === [] ? [] : [new OdooRecord($rows[0], $rows)];
    }

    public function odooRef(OdooRecord $record, ImportContext $ctx): ?string
    {
        return 'as_at:'.$this->asAt($ctx)->toDateString();
    }

    private function asAt(ImportContext $ctx): Carbon
    {
        $asAt = $ctx->options['as_at'] ?? null;
        if ($asAt === null) {
            throw new RowFailed('Opening balances need the cut-over date (--as-at=YYYY-MM-DD).');
        }

        return $ctx->date($asAt, 'cut-over date');
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $asAt = $this->asAt($ctx);
        $lines = [];
        foreach ($record->lineRows as $row) {
            $line = $this->line($row, $ctx);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            throw new RowFailed('Every balance in the file is zero -- nothing to carry across.');
        }

        try {
            $entry = Ledger::createJournalEntry(
                companyId: $ctx->company->id,
                entryDate: $asAt,
                narration: "Opening balances migrated from Odoo as at {$asAt->toDateString()}",
                lines: $lines,
                voucherType: JournalEntry::TYPE_JOURNAL,
                createdByUserId: $ctx->actor?->id,
                sourceType: 'odoo_opening_balances',
                sourceId: $ctx->run->id,
            );

            return Ledger::postEntry($entry, $ctx->actor?->id);
        } catch (LedgerRuleViolation $e) {
            throw new RowFailed($e->getMessage());
        }
    }

    /** @return array{account_id: string, debit_sgd: string, credit_sgd: string, description: string}|null */
    private function line(OdooRow $row, ImportContext $ctx): ?array
    {
        $code = $row->get('code', 'account code');
        if ($code === null) {
            $label = $row->get('account', 'account_id');
            $code = $label === null ? null : strtok($label, " \t");
        }
        if ($code === null) {
            throw new RowFailed("Row {$row->number}: no account code.");
        }
        $account = Account::where('company_id', $ctx->company->id)->where('code', $code)->first();
        if ($account === null) {
            throw new RowFailed("Row {$row->number}: account {$code} does not exist here -- import the chart of accounts first.");
        }

        if ($row->get('debit', 'credit') !== null) {
            $net = ($ctx->money($row->get('debit'), 'debit', required: false) ?? Money::of(0))
                ->minus($ctx->money($row->get('credit'), 'credit', required: false) ?? Money::of(0));
        } else {
            $net = $ctx->money($row->get('balance', 'ending balance'), "row {$row->number} balance");
        }
        if ($net->toFloat() == 0.0) {
            return null;
        }

        $positive = $net->toFloat() > 0;
        $amount = $positive ? $net : Money::of(0)->minus($net);

        return [
            'account_id' => $account->id,
            'debit_sgd' => $positive ? $amount->toString() : '0',
            'credit_sgd' => $positive ? '0' : $amount->toString(),
            'description' => 'Odoo opening balance',
        ];
    }

    public function describe(Model $model): string
    {
        return "Journal voucher {$model->voucher_number} posted, SGD {$model->totalDebit()->toString()} each side";
    }
}
