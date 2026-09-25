<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\Account;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\RowFailed;
use Illuminate\Database\Eloquent\Model;

/**
 * Odoo Chart of Accounts (account.account) -> Account.
 *
 * Columns: `id`, `code`, `name`, `account_type` (Odoo 16+'s technical
 * value such as `asset_receivable`, or its label such as "Receivable";
 * Odoo <=15's `user_type_id` label is accepted too). An account whose
 * code already exists in this company -- the seeded chart, say -- is
 * linked, not duplicated or renamed: the Websoft account keeps its own
 * name and type, and a type disagreement is reported as a warning.
 */
class AccountsImporter extends EntityImporter
{
    /** Odoo's account type (technical value or label, lower case) -> this system's five types. */
    private const TYPES = [
        'asset_receivable' => Account::TYPE_ASSET, 'receivable' => Account::TYPE_ASSET,
        'asset_cash' => Account::TYPE_ASSET, 'bank and cash' => Account::TYPE_ASSET,
        'asset_current' => Account::TYPE_ASSET, 'current assets' => Account::TYPE_ASSET,
        'asset_non_current' => Account::TYPE_ASSET, 'non-current assets' => Account::TYPE_ASSET,
        'asset_prepayments' => Account::TYPE_ASSET, 'prepayments' => Account::TYPE_ASSET,
        'asset_fixed' => Account::TYPE_ASSET, 'fixed assets' => Account::TYPE_ASSET,
        'liability_payable' => Account::TYPE_LIABILITY, 'payable' => Account::TYPE_LIABILITY,
        'liability_credit_card' => Account::TYPE_LIABILITY, 'credit card' => Account::TYPE_LIABILITY,
        'liability_current' => Account::TYPE_LIABILITY, 'current liabilities' => Account::TYPE_LIABILITY,
        'liability_non_current' => Account::TYPE_LIABILITY, 'non-current liabilities' => Account::TYPE_LIABILITY,
        'equity' => Account::TYPE_EQUITY,
        'equity_unaffected' => Account::TYPE_EQUITY, 'current year earnings' => Account::TYPE_EQUITY,
        'income' => Account::TYPE_REVENUE,
        'income_other' => Account::TYPE_REVENUE, 'other income' => Account::TYPE_REVENUE,
        'expense' => Account::TYPE_EXPENSE, 'expenses' => Account::TYPE_EXPENSE,
        'expense_depreciation' => Account::TYPE_EXPENSE, 'depreciation' => Account::TYPE_EXPENSE,
        'expense_direct_cost' => Account::TYPE_EXPENSE, 'cost of revenue' => Account::TYPE_EXPENSE,
    ];

    public function entity(): string
    {
        return 'accounts';
    }

    public function targetType(Model $model): string
    {
        return 'account';
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $code = $row->get('code');
        $name = $row->get('name', 'account name');
        if ($code === null || $name === null) {
            throw new RowFailed('An account needs both a code and a name.');
        }
        if (mb_strlen($code) > 20) {
            throw new RowFailed("Account code \"{$code}\" is longer than 20 characters.");
        }

        $odooType = $row->get('account_type', 'type', 'user_type_id', 'account type');
        if ($odooType === null) {
            throw new RowFailed("Account {$code} has no account_type.");
        }
        $type = self::TYPES[mb_strtolower($odooType)] ?? null;
        if ($type === null) {
            // off_balance and anything unrecognised: no equivalent here,
            // and guessing a type would misstate the trial balance.
            throw new RowFailed("Odoo account type \"{$odooType}\" has no equivalent here.");
        }

        $existing = Account::where('company_id', $ctx->company->id)->where('code', $code)->first();
        if ($existing !== null) {
            if ($existing->account_type !== $type) {
                $ctx->warn("Account {$code} already exists here as {$existing->account_type}; Odoo has it as {$type}. Linked, not changed.");
            }

            return $existing;
        }

        return Account::create([
            'company_id' => $ctx->company->id,
            'code' => $code,
            'name' => mb_substr($name, 0, 200),
            'account_type' => $type,
            'is_active' => $this->isActive($record),
        ]);
    }

    /** Odoo retires an account with `deprecated` (<=16) or `active` (17+). */
    private function isActive(OdooRecord $record): bool
    {
        if ($record->row->get('deprecated') !== null) {
            return ! ImportContext::truthy($record->row->get('deprecated'));
        }
        if ($record->row->get('active') !== null) {
            return ImportContext::truthy($record->row->get('active'));
        }

        return true;
    }

    public function describe(Model $model): string
    {
        return "{$model->code} {$model->name} ({$model->account_type})";
    }
}
