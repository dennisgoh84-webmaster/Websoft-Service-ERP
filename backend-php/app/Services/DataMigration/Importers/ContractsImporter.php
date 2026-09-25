<?php

namespace App\Services\DataMigration\Importers;

use App\Models\Contract;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\SourceRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * ODOO Subscriptions / ZSOFT Contracts -> Contract, keeping the old
 * contract number.
 *
 * ODOO has no contracted hours, so a service-support contract's hours
 * come from a column added to the export by hand (Contracted hours),
 * with the hours already used carried as Consumed hours -- migrated
 * Service Records do not deduct them a second time. Contract kind
 * blank means service_support when Contracted hours is filled in, else
 * annual.
 *
 * Nothing is invoiced on import: a migrated contract's invoices arrive
 * through the Invoices module, as history.
 */
class ContractsImporter extends EntityImporter
{
    /** Old status (value or label, lower case, ODOO's "3_" prefix stripped) -> contract status. */
    private const STATUSES = [
        'draft' => Contract::STATUS_DRAFT, 'quotation' => Contract::STATUS_DRAFT, 'sent' => Contract::STATUS_DRAFT, 'pending' => Contract::STATUS_DRAFT,
        'active' => Contract::STATUS_ACTIVE, 'progress' => Contract::STATUS_ACTIVE, 'in progress' => Contract::STATUS_ACTIVE, 'open' => Contract::STATUS_ACTIVE,
        'paused' => Contract::STATUS_ACTIVE, 'renewal' => Contract::STATUS_ACTIVE, 'to renew' => Contract::STATUS_ACTIVE,
        'sale' => Contract::STATUS_ACTIVE, 'sales order' => Contract::STATUS_ACTIVE, 'running' => Contract::STATUS_ACTIVE, 'valid' => Contract::STATUS_ACTIVE,
        'exceeded' => Contract::STATUS_EXCEEDED,
        'renewed' => Contract::STATUS_RENEWED,
        'expired' => Contract::STATUS_EXPIRED, 'churn' => Contract::STATUS_EXPIRED, 'churned' => Contract::STATUS_EXPIRED, 'closed' => Contract::STATUS_EXPIRED,
        'close' => Contract::STATUS_EXPIRED, 'done' => Contract::STATUS_EXPIRED, 'terminated' => Contract::STATUS_EXPIRED, 'cancelled' => Contract::STATUS_EXPIRED,
    ];

    public function entity(): string
    {
        return 'contracts';
    }

    public function label(): string
    {
        return 'Contracts';
    }

    public function fields(): array
    {
        return self::sourceIdField('If left unmapped, the contract number is used.') + [
            'name' => ['label' => 'Contract number', 'required' => true, 'aliases' => ['name', 'order reference', 'reference', 'code', 'contract no', 'contract number', 'contract_no']],
        ] + self::partyFields() + [
            'subscription_state' => ['label' => 'Status', 'required' => true, 'aliases' => ['subscription_state', 'stage_id', 'stage', 'state', 'status'], 'hint' => 'Draft / Active (in progress) / Renewed / Expired (closed).'],
            'start_date' => ['label' => 'Start date', 'required' => true, 'aliases' => ['start_date', 'date_start', 'start date']],
            'end_date' => ['label' => 'End date', 'aliases' => ['end_date', 'end date', 'expiry date'], 'hint' => 'Blank = the standard 12-month term.'],
            'recurring_total' => ['label' => 'Contract value (SGD, before GST)', 'required' => true, 'aliases' => ['recurring_total', 'amount_untaxed', 'recurring revenue', 'untaxed amount', 'recurring price', 'contract value', 'amount']],
            'contract_kind' => ['label' => 'Contract kind', 'aliases' => ['contract_kind', 'contract kind', 'contract type'], 'hint' => 'service_support / annual / ad_hoc. Blank = service_support when hours are given, else annual.'],
            'contracted_hours' => ['label' => 'Contracted hours', 'aliases' => ['contracted_hours', 'contracted hours', 'hours']],
            'consumed_hours' => ['label' => 'Consumed hours', 'aliases' => ['consumed_hours', 'consumed hours', 'used hours', 'hours used']],
            'hourly_rate' => ['label' => 'Hourly rate (SGD)', 'aliases' => ['hourly_rate', 'hourly rate', 'rate']],
            'user_id' => ['label' => 'Salesperson', 'aliases' => ['user_id', 'salesperson', 'sales staff', 'salesman']],
        ] + self::currencyField();
    }

    public function targetType(Model $model): string
    {
        return 'contract';
    }

    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'name');
    }

    public function import(SourceRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $ctx->requireSgd($row);

        $number = $row->get('name');
        if ($number === null) {
            throw new RowFailed('Contract number is required.');
        }
        $ctx->requireUnusedNumber(Contract::class, 'contract_number', $number);
        $customer = $ctx->customer($row);

        $oldStatus = $row->get('subscription_state');
        if ($oldStatus === null) {
            throw new RowFailed('Status is required.');
        }
        $status = self::STATUSES[preg_replace('/^\d+_/', '', mb_strtolower($oldStatus))] ?? null;
        if ($status === null) {
            throw new RowFailed("Status \"{$oldStatus}\" has no equivalent contract status here.");
        }

        $start = $ctx->date($row->get('start_date'), 'Start date');
        $end = $ctx->date($row->get('end_date'), 'End date', required: false);
        if ($end === null) {
            // Often open-ended in ODOO; a contract here always has an
            // end. The standard term is the one rule we have for it.
            $end = $start->copy()->addMonthsNoOverflow(Contract::STANDARD_CONTRACT_MONTHS)->subDay();
            $ctx->warn('No end date; set to the standard '.Contract::STANDARD_CONTRACT_MONTHS."-month term, {$end->format('d/m/Y')}.");
        }
        if ($end->lt($start)) {
            throw new RowFailed('End date is before start date.');
        }

        $value = $ctx->money($row->get('recurring_total'), 'Contract value');

        $hours = $this->hours($row->get('contracted_hours'), 'Contracted hours');
        $kind = $row->get('contract_kind') !== null ? str_replace([' ', '-'], '_', mb_strtolower($row->get('contract_kind')))
            : ($hours !== null ? Contract::KIND_SERVICE_SUPPORT : Contract::KIND_ANNUAL);
        if (! in_array($kind, [Contract::KIND_SERVICE_SUPPORT, Contract::KIND_ANNUAL, Contract::KIND_AD_HOC], true)) {
            throw new RowFailed("Contract kind \"{$kind}\" must be service_support, annual or ad_hoc.");
        }
        $contractedMinutes = (int) round(($hours ?? 0) * 60);
        if ($kind === Contract::KIND_SERVICE_SUPPORT && $contractedMinutes < Contract::MINIMUM_CONTRACTED_HOURS * 60) {
            throw new RowFailed('A service_support contract needs Contracted hours of at least '.Contract::MINIMUM_CONTRACTED_HOURS.'.');
        }
        $consumedMinutes = (int) round(($this->hours($row->get('consumed_hours'), 'Consumed hours') ?? 0) * 60);

        $salesStaffId = null;
        if (($salesperson = $row->get('user_id')) !== null) {
            $salesStaffId = $ctx->staff($salesperson)?->id;
        }

        return Contract::create([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'contract_number' => $number,
            'status' => $status,
            'contract_kind' => $kind,
            'contracted_minutes' => $contractedMinutes,
            'consumed_minutes' => $consumedMinutes,
            'contract_value_sgd' => $value->toString(),
            'hourly_rate_sgd' => $ctx->money($row->get('hourly_rate'), 'Hourly rate', required: false)?->toString(),
            'sales_staff_id' => $salesStaffId,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'activated_at' => $status === Contract::STATUS_DRAFT ? null : $start,
        ]);
    }

    private function hours(?string $value, string $label): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            throw new RowFailed("{$label} \"{$value}\" is not a number of hours.");
        }

        return (float) $value;
    }

    public function describe(Model $model): string
    {
        return "Contract {$model->contract_number} ({$model->contract_kind}, {$model->status})";
    }
}
