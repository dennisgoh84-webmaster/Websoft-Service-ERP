<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\Contract;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\RowFailed;
use Illuminate\Database\Eloquent\Model;

/**
 * Odoo Subscriptions (sale.order with a recurring plan in Odoo 16+,
 * sale.subscription before) -> Contract, keeping Odoo's reference as
 * the contract number.
 *
 * Odoo has no notion of contracted hours, so four columns this system
 * needs are ADDED to the export by hand before importing -- they are
 * not Odoo fields:
 *
 * - `contract_kind`: service_support | annual | ad_hoc. Blank means
 *   service_support when `contracted_hours` is filled in, else annual.
 * - `contracted_hours`: a service_support contract's hours
 *   (SRV-002/012's 10-hour minimum applies, as to any contract).
 * - `consumed_hours`: hours already used in Odoo, carried as the
 *   contract's balance -- the imported timesheets do not re-deduct it.
 * - `hourly_rate`: optional, as on any contract.
 *
 * Nothing is invoiced on import: contract activation normally issues
 * the annual invoice, but a migrated contract's invoices arrive with
 * the invoices import, as history.
 */
class SubscriptionsImporter extends EntityImporter
{
    /** Odoo stage/state (technical value or label, lower case, "3_" prefix stripped) -> contract status. */
    private const STATUSES = [
        'draft' => Contract::STATUS_DRAFT, 'quotation' => Contract::STATUS_DRAFT, 'sent' => Contract::STATUS_DRAFT,
        'progress' => Contract::STATUS_ACTIVE, 'in progress' => Contract::STATUS_ACTIVE, 'open' => Contract::STATUS_ACTIVE,
        'paused' => Contract::STATUS_ACTIVE, 'renewal' => Contract::STATUS_ACTIVE, 'to renew' => Contract::STATUS_ACTIVE,
        'sale' => Contract::STATUS_ACTIVE, 'sales order' => Contract::STATUS_ACTIVE,
        'renewed' => Contract::STATUS_RENEWED,
        'churn' => Contract::STATUS_EXPIRED, 'churned' => Contract::STATUS_EXPIRED, 'closed' => Contract::STATUS_EXPIRED,
        'close' => Contract::STATUS_EXPIRED, 'done' => Contract::STATUS_EXPIRED,
    ];

    public function entity(): string
    {
        return 'subscriptions';
    }

    public function targetType(Model $model): string
    {
        return 'contract';
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $ctx->requireSgd($row);

        $number = $row->get('name', 'order reference', 'reference', 'code');
        if ($number === null) {
            throw new RowFailed('A subscription needs its reference (name).');
        }
        $ctx->requireUnusedNumber(Contract::class, 'contract_number', $number);
        $customer = $ctx->customer($row, 'partner_id', 'customer');

        $odooStatus = $row->get('subscription_state', 'stage_id', 'stage', 'state', 'status');
        if ($odooStatus === null) {
            throw new RowFailed("Subscription {$number} has no stage/state.");
        }
        $status = self::STATUSES[preg_replace('/^\d+_/', '', mb_strtolower($odooStatus))] ?? null;
        if ($status === null) {
            throw new RowFailed("Odoo subscription state \"{$odooStatus}\" has no equivalent contract status here.");
        }

        $start = $ctx->date($row->get('start_date', 'date_start', 'start date'), 'start date');
        $end = $ctx->date($row->get('end_date', 'date', 'end date'), 'end date', required: false);
        if ($end === null) {
            // Odoo subscriptions are often open-ended; a contract here
            // always has an end. The standard term is the one rule we
            // have for it (Contract::STANDARD_CONTRACT_MONTHS).
            $end = $start->copy()->addMonthsNoOverflow(Contract::STANDARD_CONTRACT_MONTHS)->subDay();
            $ctx->warn("No end date in Odoo; set to the standard {$this->months()}-month term, {$end->toDateString()}.");
        }
        if ($end->lt($start)) {
            throw new RowFailed('End date is before start date.');
        }

        $value = $ctx->money($row->get('recurring_total', 'amount_untaxed', 'recurring revenue', 'untaxed amount', 'recurring price'), 'contract value');

        $hours = $this->hours($row->get('contracted_hours'), 'contracted_hours');
        $kind = $row->get('contract_kind') !== null ? mb_strtolower($row->get('contract_kind'))
            : ($hours !== null ? Contract::KIND_SERVICE_SUPPORT : Contract::KIND_ANNUAL);
        if (! in_array($kind, [Contract::KIND_SERVICE_SUPPORT, Contract::KIND_ANNUAL, Contract::KIND_AD_HOC], true)) {
            throw new RowFailed("contract_kind \"{$kind}\" must be service_support, annual or ad_hoc.");
        }
        $contractedMinutes = (int) round(($hours ?? 0) * 60);
        if ($kind === Contract::KIND_SERVICE_SUPPORT && $contractedMinutes < Contract::MINIMUM_CONTRACTED_HOURS * 60) {
            throw new RowFailed('A service_support contract needs contracted_hours of at least '.Contract::MINIMUM_CONTRACTED_HOURS.'.');
        }
        $consumedMinutes = (int) round(($this->hours($row->get('consumed_hours'), 'consumed_hours') ?? 0) * 60);

        $salesStaffId = null;
        $salesperson = $row->get('user_id', 'salesperson');
        if ($salesperson !== null) {
            $salesStaffId = $ctx->user($salesperson)?->id;
            if ($salesStaffId === null) {
                $ctx->warn("Salesperson \"{$salesperson}\" is not a staff user here; left blank.");
            }
        }

        $hourlyRate = $ctx->money($row->get('hourly_rate'), 'hourly_rate', required: false);

        return Contract::create([
            'company_id' => $ctx->company->id,
            'customer_id' => $customer->id,
            'contract_number' => $number,
            'status' => $status,
            'contract_kind' => $kind,
            'contracted_minutes' => $contractedMinutes,
            'consumed_minutes' => $consumedMinutes,
            'contract_value_sgd' => $value->toString(),
            'hourly_rate_sgd' => $hourlyRate?->toString(),
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

    private function months(): int
    {
        return Contract::STANDARD_CONTRACT_MONTHS;
    }

    public function describe(Model $model): string
    {
        return "Contract {$model->contract_number} ({$model->contract_kind}, {$model->status})";
    }
}
