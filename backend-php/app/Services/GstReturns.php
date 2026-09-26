<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AccountingPeriod;
use App\Models\GstReturn;
use App\Models\GstReturnLine;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The GST F5 workflow (Dennis, 2026-09-26, open item 4b.4):
 *
 *   "Every month, when the last month transactions all settled keying
 *   in, they will look for the period ... and locked it up. In each
 *   period, there is a button to do GST Calculation ... based on the
 *   locked data ... pull out the figures based on those document
 *   status and sum up like form 5 and keep it there ... the report will
 *   pull those required information from what is saved inside. GST
 *   supporting reports also ... all use the data that is kept inside."
 *
 * calculate() only runs on a closed (fully locked) period. It takes:
 *
 * - Output: every Sales Invoice issued in the period (by its Singapore
 *   issue date), whatever it has since become -- outstanding, paid or
 *   written off (bad-debt relief is a separate claim, box 11) -- except
 *   invoices brought in by Data Migration, which are history already
 *   filed from the old system. Its tax code decides the box: SR -> 1,
 *   ZR -> 2, ES -> 3, OS -> out of scope (revenue, box 13, only). Any
 *   other code counts as standard-rated when it carries GST, out of
 *   scope when it carries none.
 * - Input: every supplier bill dated in the period that reached the
 *   books -- approved, partly paid or paid; one still awaiting a match
 *   or held as an exception is not in Accounts Payable and is left out.
 *   A bill carries no tax code, so one charging GST counts in box 5
 *   (taxable purchases) and box 7; one charging none is listed as "no
 *   GST" and left out of box 5 (it may be from a supplier who is not
 *   GST-registered).
 *
 * The result -- the boxes and every document line behind them -- is
 * kept in gst_returns / gst_return_lines. Recalculating adds the next
 * version and marks the previous one superseded; nothing is deleted.
 */
class GstReturns
{
    private const BOX_BY_TAX_CODE = ['SR' => '1', 'ZR' => '2', 'ES' => '3', 'OS' => 'out_of_scope'];

    private const BOOKED_BILL_STATUSES = [
        SupplierInvoice::STATUS_APPROVED, SupplierInvoice::STATUS_PARTIALLY_PAID, SupplierInvoice::STATUS_PAID,
    ];

    public static function current(AccountingPeriod $period): ?GstReturn
    {
        return GstReturn::where('accounting_period_id', $period->id)->where('status', GstReturn::STATUS_CURRENT)->first();
    }

    public static function calculate(AccountingPeriod $period, User $actor): GstReturn
    {
        if ($period->status !== AccountingPeriod::STATUS_CLOSED) {
            throw new ApiException(409, "Lock the period first: {$period->name} is still open, so its figures can still change. Close All, then run the GST Calculation.");
        }

        return DB::transaction(function () use ($period, $actor) {
            $lines = [...self::outputLines($period), ...self::inputLines($period)];

            $sum = function (string $direction, ?array $boxes, string $field) use ($lines): Money {
                $total = Money::of(0);
                foreach ($lines as $l) {
                    if ($l['direction'] === $direction && ($boxes === null || in_array($l['box'], $boxes, true))) {
                        $total = $total->plus(Money::of($l[$field]));
                    }
                }

                return $total;
            };
            $b1 = $sum(GstReturnLine::OUTPUT, ['1'], 'net_sgd');
            $b2 = $sum(GstReturnLine::OUTPUT, ['2'], 'net_sgd');
            $b3 = $sum(GstReturnLine::OUTPUT, ['3'], 'net_sgd');
            $b5 = $sum(GstReturnLine::INPUT, ['5'], 'net_sgd');
            $b6 = $sum(GstReturnLine::OUTPUT, null, 'gst_sgd');
            $b7 = $sum(GstReturnLine::INPUT, null, 'gst_sgd');
            $b13 = $sum(GstReturnLine::OUTPUT, null, 'net_sgd');

            $previous = self::current($period);
            if ($previous !== null) {
                $previous->status = GstReturn::STATUS_SUPERSEDED;
                $previous->superseded_at = Carbon::now();
                $previous->save();
            }
            $version = (int) GstReturn::where('accounting_period_id', $period->id)->max('version') + 1;

            $return = GstReturn::create([
                'company_id' => $period->company_id,
                'accounting_period_id' => $period->id,
                'period_start' => $period->period_start->toDateString(),
                'period_end' => $period->period_end->toDateString(),
                'version' => $version,
                'status' => GstReturn::STATUS_CURRENT,
                'box_1_sgd' => $b1->toString(),
                'box_2_sgd' => $b2->toString(),
                'box_3_sgd' => $b3->toString(),
                'box_4_sgd' => $b1->plus($b2)->plus($b3)->toString(),
                'box_5_sgd' => $b5->toString(),
                'box_6_sgd' => $b6->toString(),
                'box_7_sgd' => $b7->toString(),
                'box_8_sgd' => $b6->minus($b7)->toString(),
                'box_13_sgd' => $b13->toString(),
                'output_document_count' => count(array_filter($lines, fn ($l) => $l['direction'] === GstReturnLine::OUTPUT)),
                'input_document_count' => count(array_filter($lines, fn ($l) => $l['direction'] === GstReturnLine::INPUT)),
                'calculated_by_user_id' => $actor->id,
            ]);
            foreach ($lines as $l) {
                GstReturnLine::create($l + ['gst_return_id' => $return->id]);
            }

            Audit::record(
                entityType: 'gst_return',
                entityId: $return->id,
                action: $previous ? 'recalculated' : 'calculated',
                actorUserId: $actor->id,
                companyId: $period->company_id,
                details: sprintf(
                    'GST Calculation v%d for %s: output tax SGD %s, input tax SGD %s, net SGD %s (%d sales, %d purchase documents)',
                    $version, $period->name, $b6->toString(), $b7->toString(), $b6->minus($b7)->toString(),
                    $return->output_document_count, $return->input_document_count,
                ),
                oldValue: $previous ? ['version' => $previous->version, 'box_8_sgd' => (string) $previous->box_8_sgd] : null,
                newValue: ['version' => $version, 'box_8_sgd' => $b6->minus($b7)->toString()],
            );

            return $return->fresh();
        });
    }

    /** @return list<array<string, mixed>> */
    private static function outputLines(AccountingPeriod $period): array
    {
        return Invoice::with('customer')
            ->where('company_id', $period->company_id)
            ->whereNull('migrated_at')
            ->whereDate('issued_at', '>=', $period->period_start->toDateString())
            ->whereDate('issued_at', '<=', $period->period_end->toDateString())
            ->orderBy('issued_at')->orderBy('invoice_number')
            ->get()
            ->map(function (Invoice $i) {
                $gst = Money::of($i->gst_amount_sgd ?? 0);
                $code = strtoupper((string) $i->tax_code);

                return [
                    'direction' => GstReturnLine::OUTPUT,
                    'document_type' => 'invoice',
                    'document_id' => $i->id,
                    'document_number' => $i->invoice_number,
                    'document_date' => Carbon::parse($i->issued_at)->setTimezone(config('app.timezone'))->toDateString(),
                    'party_id' => $i->customer_id,
                    'party_name' => $i->customer?->name,
                    'tax_code' => $i->tax_code,
                    'box' => self::BOX_BY_TAX_CODE[$code] ?? ($gst->toFloat() > 0 ? '1' : 'out_of_scope'),
                    'net_sgd' => Money::of($i->amount_sgd ?? 0)->toString(),
                    'gst_sgd' => $gst->toString(),
                ];
            })->all();
    }

    /** @return list<array<string, mixed>> */
    private static function inputLines(AccountingPeriod $period): array
    {
        return SupplierInvoice::with('supplier')
            ->where('company_id', $period->company_id)
            ->whereIn('status', self::BOOKED_BILL_STATUSES)
            ->whereDate('invoice_date', '>=', $period->period_start->toDateString())
            ->whereDate('invoice_date', '<=', $period->period_end->toDateString())
            ->orderBy('invoice_date')->orderBy('bill_number')
            ->get()
            ->map(function (SupplierInvoice $b) {
                $gst = Money::of($b->gst_amount_sgd ?? 0);

                return [
                    'direction' => GstReturnLine::INPUT,
                    'document_type' => 'supplier_invoice',
                    'document_id' => $b->id,
                    'document_number' => $b->supplier_invoice_no ? "{$b->bill_number} ({$b->supplier_invoice_no})" : $b->bill_number,
                    'document_date' => Carbon::parse($b->invoice_date)->toDateString(),
                    'party_id' => $b->supplier_id,
                    'party_name' => $b->supplier?->name,
                    'tax_code' => null,
                    'box' => $gst->toFloat() > 0 ? '5' : 'no_gst',
                    'net_sgd' => Money::of($b->amount_sgd ?? 0)->toString(),
                    'gst_sgd' => $gst->toString(),
                ];
            })->all();
    }

    /**
     * The saved calculations for the given companies whose period starts
     * within [$from, $to] -- what the GST Return and its supporting
     * reports read.
     *
     * @param  list<string>  $companyIds
     * @return Collection<int, GstReturn>
     */
    public static function savedBetween(array $companyIds, Carbon $from, Carbon $to): Collection
    {
        return GstReturn::with('period')
            ->whereIn('company_id', $companyIds)
            ->where('status', GstReturn::STATUS_CURRENT)
            ->whereDate('period_start', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->orderBy('period_start')
            ->get();
    }

    /**
     * Periods in the range with no saved calculation -- a report over
     * them would be incomplete, so the report says which.
     *
     * @param  list<string>  $companyIds
     * @return Collection<int, AccountingPeriod>
     */
    public static function uncalculatedBetween(array $companyIds, Carbon $from, Carbon $to): Collection
    {
        return AccountingPeriod::whereIn('company_id', $companyIds)
            ->whereDate('period_start', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gst_returns')
                ->whereColumn('gst_returns.accounting_period_id', 'accounting_periods.id')
                ->where('gst_returns.status', GstReturn::STATUS_CURRENT))
            ->orderBy('period_start')
            ->get();
    }
}
