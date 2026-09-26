<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AccountingPeriod;
use App\Models\CreditNote;
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
 *   Its purchase tax code decides the box, as a sales invoice's does
 *   (Dennis, 2026-09-26: "like Sales Invoice Logic"): TX (standard-rated)
 *   and ZP (zero-rated) are taxable purchases, box 5, with TX's GST in
 *   box 7; EP (exempt), OP (out of scope) and NR (supplier not registered
 *   for GST) are listed but left out of box 5. A bill from before bills
 *   carried a code counts in box 5 when it charged GST.
 *
 * Once a calculation is marked submitted to IRAS -- by whom and when --
 * the month is locked: it cannot be recalculated and its period cannot
 * be reopened or have any lock lifted.
 *
 * Until it is revised (Dennis, 2026-09-26: "Have to allow resubmission
 * like revision but have to keep the old record"). Revise, with a
 * reason, marks the submitted return as under revision -- who, when,
 * why -- and nothing else about it changes. The month can then be
 * unlocked and corrected, locked again and recalculated: the new
 * version records which submitted return it revises, and is submitted
 * in its turn, which locks the month again. Every submitted version
 * stays on file with its figures, documents and who submitted it.
 * (IRAS takes a correction to a return already filed as a GST F7.)
 *
 * The result -- the boxes and every document line behind them -- is
 * kept in gst_returns / gst_return_lines. Recalculating adds the next
 * version and marks the previous one superseded; nothing is deleted.
 */
class GstReturns
{
    private const BOX_BY_TAX_CODE = ['SR' => '1', 'ZR' => '2', 'ES' => '3', 'OS' => 'out_of_scope'];

    private const BOX_BY_PURCHASE_CODE = ['TX' => '5', 'ZP' => '5', 'EP' => 'not_taxable', 'OP' => 'not_taxable', 'NR' => 'not_taxable'];

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
        self::assertNotSubmitted($period);

        return DB::transaction(function () use ($period, $actor) {
            $lines = [...self::outputLines($period), ...self::creditNoteLines($period), ...self::inputLines($period)];

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
            // A version made after a submission revises the latest submitted one.
            $revises = GstReturn::where('accounting_period_id', $period->id)->whereNotNull('submitted_at')
                ->orderByDesc('version')->first();
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
                'revises_gst_return_id' => $revises?->id,
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
                    'GST Calculation v%d%s for %s: output tax SGD %s, input tax SGD %s, net SGD %s (%d sales, %d purchase documents)',
                    $version, $revises ? " (revision of submitted v{$revises->version})" : '', $period->name, $b6->toString(), $b7->toString(), $b6->minus($b7)->toString(),
                    $return->output_document_count, $return->input_document_count,
                ),
                oldValue: $previous ? ['version' => $previous->version, 'box_8_sgd' => (string) $previous->box_8_sgd] : null,
                newValue: ['version' => $version, 'box_8_sgd' => $b6->minus($b7)->toString()],
            );

            return $return->fresh();
        });
    }

    /**
     * Mark the period's current GST Calculation as submitted to IRAS --
     * who and when -- after which the month is locked for good.
     */
    public static function submit(AccountingPeriod $period, User $actor): GstReturn
    {
        $current = self::current($period);
        if ($current === null) {
            throw new ApiException(409, "Run the GST Calculation for {$period->name} before submitting it.");
        }
        self::assertNotSubmitted($period);
        if ($current->submitted_at !== null) {
            throw new ApiException(409, "GST Calculation v{$current->version} for {$period->name} is the return already submitted. Lock the month and run the GST Calculation for the revision, then submit that.");
        }
        if ($period->status !== AccountingPeriod::STATUS_CLOSED) {
            throw new ApiException(409, "{$period->name} was reopened after its GST Calculation. Lock it and recalculate before submitting.");
        }

        $current->submitted_by_user_id = $actor->id;
        $current->submitted_at = Carbon::now();
        $current->save();

        Audit::record(
            entityType: 'gst_return',
            entityId: $current->id,
            action: 'submitted_to_iras',
            actorUserId: $actor->id,
            companyId: $period->company_id,
            details: sprintf('GST Calculation v%d for %s submitted to IRAS%s (net GST SGD %s); the month is now locked',
                $current->version, $period->name,
                $current->revises ? " as a revision of v{$current->revises->version}" : '',
                Money::of($current->box_8_sgd)->toString()),
            newValue: ['submitted_at' => $current->submitted_at->toIso8601String()],
        );

        return $current->fresh();
    }

    /**
     * Open a revision of the month's submitted return: records who, when
     * and why on that return, and lets the month be unlocked, corrected
     * and recalculated. The submitted return itself is kept as it was.
     */
    public static function openRevision(AccountingPeriod $period, User $actor, string $reason): GstReturn
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException(422, 'Give the reason for revising the submitted GST return.');
        }
        $current = self::current($period);
        if ($current?->submitted_at === null) {
            throw new ApiException(409, "{$period->name} has no GST return submitted to IRAS, so there is nothing to revise.");
        }
        if ($current->revision_opened_at !== null) {
            throw new ApiException(409, sprintf('A revision of %s was already opened on %s by %s.',
                $period->name, $current->revision_opened_at->format('d/m/Y H:i'), $current->revisionOpenedBy?->full_name ?? 'someone'));
        }

        $current->revision_opened_by_user_id = $actor->id;
        $current->revision_opened_at = Carbon::now();
        $current->revision_reason = $reason;
        $current->save();

        Audit::record(
            entityType: 'gst_return',
            entityId: $current->id,
            action: 'revision_opened',
            actorUserId: $actor->id,
            companyId: $period->company_id,
            details: sprintf('Revision opened on %s\'s submitted GST return v%d: %s. The submitted return is kept; the month can be corrected, recalculated and submitted again.',
                $period->name, $current->version, $reason),
            newValue: ['revision_reason' => $reason],
        );

        return $current->fresh();
    }

    /**
     * Refuses anything that would change a month already submitted to
     * IRAS, unless a revision of that submission has been opened.
     */
    public static function assertNotSubmitted(AccountingPeriod $period): void
    {
        $current = self::current($period);
        if ($current?->submitted_at !== null && $current->revision_opened_at === null) {
            throw new ApiException(409, sprintf(
                '%s was submitted to IRAS on %s by %s and is locked. To correct it, Revise the submitted return (the submitted one is kept).',
                $period->name, $current->submitted_at->format('d/m/Y H:i'), $current->submittedBy?->full_name ?? 'someone',
            ));
        }
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

    /**
     * Credit notes issued in the period (BILL-003; #49: "counts in the GST
     * Calculation"): each takes its amounts off the box its invoice's tax
     * code decides, in the month the credit note is issued -- including one
     * on an invoice brought in by Data Migration, since the credit note
     * itself is new here.
     *
     * @return list<array<string, mixed>>
     */
    private static function creditNoteLines(AccountingPeriod $period): array
    {
        return CreditNote::with(['customer', 'invoice'])
            ->where('company_id', $period->company_id)
            ->where('status', CreditNote::STATUS_ISSUED)
            ->whereDate('issued_at', '>=', $period->period_start->toDateString())
            ->whereDate('issued_at', '<=', $period->period_end->toDateString())
            ->orderBy('issued_at')->orderBy('credit_note_number')
            ->get()
            ->map(function (CreditNote $n) {
                $gst = Money::of($n->gst_amount_sgd ?? 0);
                $code = strtoupper((string) $n->tax_code);

                return [
                    'direction' => GstReturnLine::OUTPUT,
                    'document_type' => 'credit_note',
                    'document_id' => $n->id,
                    'document_number' => "{$n->credit_note_number} (on {$n->invoice?->invoice_number})",
                    'document_date' => Carbon::parse($n->issued_at)->setTimezone(config('app.timezone'))->toDateString(),
                    'party_id' => $n->customer_id,
                    'party_name' => $n->customer?->name,
                    'tax_code' => $n->tax_code,
                    'box' => self::BOX_BY_TAX_CODE[$code] ?? ($gst->toFloat() > 0 ? '1' : 'out_of_scope'),
                    'net_sgd' => Money::of(0)->minus(Money::of($n->amount_sgd))->toString(),
                    'gst_sgd' => Money::of(0)->minus($gst)->toString(),
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
                $code = strtoupper((string) $b->tax_code);
                $box = self::BOX_BY_PURCHASE_CODE[$code] ?? ($b->tax_code === null
                    ? ($gst->toFloat() > 0 ? '5' : 'no_gst')
                    : ($gst->toFloat() > 0 ? '5' : 'not_taxable'));

                return [
                    'direction' => GstReturnLine::INPUT,
                    'document_type' => 'supplier_invoice',
                    'document_id' => $b->id,
                    'document_number' => $b->supplier_invoice_no ? "{$b->bill_number} ({$b->supplier_invoice_no})" : $b->bill_number,
                    'document_date' => Carbon::parse($b->invoice_date)->toDateString(),
                    'party_id' => $b->supplier_id,
                    'party_name' => $b->supplier?->name,
                    'tax_code' => $b->tax_code,
                    'box' => $box,
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
