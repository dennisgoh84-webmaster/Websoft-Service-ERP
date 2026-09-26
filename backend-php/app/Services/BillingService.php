<?php

namespace App\Services;

use App\Exceptions\BillingRuleViolation;
use App\Exceptions\InventoryRuleViolation;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Invoice;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Billing business logic -- BILL-001 (annual upfront), BILL-002 (no
 * approval needed), BILL-005 (revenue recognized on invoice), and
 * SRV-008 (excess usage billed at the contract's own blended rate).
 * Mirrors backend/app/services/billing.py exactly.
 *
 * Every invoice raised here is a tax invoice: GST is applied per the
 * company's tax code (App\Services\Tax), it is serially numbered
 * (App\Services\Numbering), and its due date comes from the
 * customer's own payment terms (confirmed 2026-09-10: terms vary per
 * customer -- CompanyIndividual::payment_terms_days). A customer with
 * no agreed terms gets no due date rather than an invented one. Every
 * invoice also posts to the General Ledger in the same step
 * (ACC-001/003, App\Services\Posting -- Dr AR / Cr revenue / Cr GST
 * output), now that GL posting is converted.
 *
 * KNOWN GAP: GP costing (`cost_sgd`) traces a CONTRACT_ANNUAL
 * invoice's cost back to the Sales Quotation that converted into the
 * contract (docs/open-business-decisions.md #32). The Quotations
 * module isn't converted yet, so `costBasisForContract()` here always
 * returns null -- the same as the Python version's own "this contract
 * wasn't created from a quotation" case, never an invented cost.
 */
class BillingService
{
    private static function costBasisForContract(Contract $contract): ?Money
    {
        // See class docblock's KNOWN GAP -- Quotations isn't converted.
        return null;
    }

    private static function dueDateFor(string $customerId, Carbon $issuedOn): ?Carbon
    {
        $customer = CompanyIndividual::find($customerId);
        if (! $customer || $customer->payment_terms_days === null) {
            return null;
        }

        return $issuedOn->copy()->addDays((int) $customer->payment_terms_days);
    }

    /** Shared construction: numbering, GST, and due date. */
    private static function buildInvoice(
        string $companyId,
        string $customerId,
        string $invoiceType,
        string $description,
        Money $netAmount,
        ?string $contractId = null,
        ?string $excessUsageRecordId = null,
        ?Money $costSgd = null,
    ): Invoice {
        $issuedOn = Carbon::today();
        [$taxCode, $gstRate, $gstAmount, $total] = Tax::applyGst($companyId, $netAmount);

        return new Invoice([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'contract_id' => $contractId,
            'excess_usage_record_id' => $excessUsageRecordId,
            'invoice_number' => Numbering::next($companyId, 'invoice'),
            'invoice_type' => $invoiceType,
            'description' => $description,
            'amount_sgd' => $netAmount->toString(),
            'tax_code' => $taxCode,
            'gst_rate' => $gstRate->toString(),
            'gst_amount_sgd' => $gstAmount->toString(),
            'total_amount_sgd' => $total->toString(),
            'due_date' => self::dueDateFor($customerId, $issuedOn),
            'cost_sgd' => $costSgd?->toString(),
        ]);
    }

    /**
     * BILL-001: full 12-month contract value, billed at contract
     * start/renewal. BILL-002: no approval required -- issued
     * directly.
     */
    public static function issueContractAnnualInvoice(Contract $contract, string $actorUserId): Invoice
    {
        $invoice = self::buildInvoice(
            companyId: $contract->company_id,
            customerId: $contract->customer_id,
            invoiceType: Invoice::TYPE_CONTRACT_ANNUAL,
            description: sprintf(
                'Annual service contract (%s to %s)',
                $contract->start_date->toDateString(),
                $contract->end_date->toDateString(),
            ),
            netAmount: Money::of($contract->contract_value_sgd),
            contractId: $contract->id,
            costSgd: self::costBasisForContract($contract),
        );
        $invoice->save();
        $invoice->refresh(); // pick up issued_at's DB default before posting

        // ACC-001/003 + BILL-005: issuing the invoice is the accounting
        // event (revenue recognised on invoice) -- post it now.
        Posting::postInvoice($invoice, $actorUserId);

        Audit::record(
            entityType: 'invoice',
            entityId: $invoice->id,
            action: 'issued',
            actorUserId: $actorUserId,
            details: "{$invoice->invoice_number}, invoice_type=contract_annual, contract_id={$contract->id}",
            newValue: [
                'invoice_number' => $invoice->invoice_number,
                'net_sgd' => (string) $invoice->amount_sgd,
                'gst_sgd' => (string) $invoice->gst_amount_sgd,
                'total_sgd' => (string) $invoice->total_amount_sgd,
                'cost_sgd' => $invoice->cost_sgd !== null ? (string) $invoice->cost_sgd : null,
            ],
        );

        return $invoice;
    }

    /**
     * A manually raised Sales Invoice: one or more lines, each
     * optionally drawing stock (Dennis, 2026-09-15 -- "Sales invoice
     * ... when pick stock n update ... will use avg cost to deduct
     * accordingly").
     *
     * The whole thing is one atomic act, as BILL-002 requires of every
     * invoice in this system -- there is no draft state to leave stock
     * reserved in. Either every stock line deducts, the header totals,
     * the GL voucher and the audit entry all land, or nothing does.
     *
     * STOCK RULES ARE NOT RE-IMPLEMENTED HERE. Each stock line goes
     * through App\Services\InventoryService::deductStock(), the same
     * call a Goods Issue Note makes, so INV-002 holds by construction:
     * an insufficient quantity refuses the WHOLE invoice rather than
     * issuing part of it, on-hand quantity can never go negative, and
     * units leave at the item's weighted average without re-weighting
     * it.
     *
     * GST is applied once to the summed net, via the company's tax
     * code -- the same single-rate treatment every other invoice and
     * every quotation in this system uses. Per-line tax codes would be
     * a new business rule and nobody has asked for one.
     *
     * Unlike the auto-issued invoices, `cost_sgd` here is a REAL cost
     * basis (the summed weighted-average cost of the stock issued), so
     * the Sales GP report shows a measured margin on these rather than
     * the stand-in 100% it reports for an invoice with no known cost.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws InventoryRuleViolation when a stock line
     *                                asks for more than the warehouse holds
     */
    public static function issueSalesInvoice(
        string $companyId,
        string $customerId,
        array $lines,
        string $actorUserId,
        ?string $description = null,
        string $currency = 'SGD',
        string $rate = '1',
    ): Invoice {
        if ($lines === []) {
            throw new BillingRuleViolation('A sales invoice needs at least one line.');
        }

        // Multi-currency: each line's price is in the invoice's currency
        // (`unit_price`, or `unit_price_sgd` for an SGD invoice); its SGD
        // figure is at the invoice's rate.
        $netFx = Money::of(0);
        $net = Money::of(0);
        $cost = Money::of(0);
        $anyCostKnown = false;
        $prepared = [];

        foreach (array_values($lines) as $i => $line) {
            $qty = (int) $line['quantity'];
            $unitPriceFx = Money::of($line['unit_price'] ?? $line['unit_price_sgd']);
            $amountFx = $unitPriceFx->multipliedBy($qty)->quantize();
            $unitPrice = Currency::toSgd($unitPriceFx, $rate);
            $amount = Currency::toSgd($amountFx, $rate);
            $netFx = $netFx->plus($amountFx);
            $net = $net->plus($amount);

            $prepared[] = [
                'company_id' => $companyId,
                'line_no' => $i + 1,
                'description' => $line['description'],
                'product_id' => $line['product_id'] ?? null,
                'stock_item_id' => $line['stock_item_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'quantity' => $qty,
                'unit_of_measure' => $line['unit_of_measure'] ?? null,
                'unit_price_sgd' => $unitPrice->toString(),
                'line_amount_sgd' => $amount->toString(),
                'unit_price_fx' => $unitPriceFx->toString(),
                'line_amount_fx' => $amountFx->toString(),
                // A known unit cost for a line that moves no stock (a
                // quotation line's cost, decision #32); a stock line's
                // cost always comes from the stock it takes instead.
                'known_unit_cost' => isset($line['unit_cost_sgd']) && $line['unit_cost_sgd'] !== null ? Money::of($line['unit_cost_sgd']) : null,
            ];
        }

        $invoice = self::buildInvoice(
            companyId: $companyId,
            customerId: $customerId,
            invoiceType: Invoice::TYPE_SALES,
            description: $description ?? self::describeLines($prepared),
            netAmount: $net,
        );
        // GST is worked out in the invoice's currency and each figure kept
        // in SGD at its rate (the GST return reads the SGD figures).
        [, , $gstFx, $totalFx] = Tax::applyGst($companyId, $netFx);
        $gstSgd = Currency::isBase($currency) ? Money::of($invoice->gst_amount_sgd) : Currency::toSgd($gstFx, $rate);
        $invoice->fill([
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'gst_amount_sgd' => $gstSgd->toString(),
            'total_amount_sgd' => $net->plus($gstSgd)->toString(),
            'amount_fx' => $netFx->toString(),
            'gst_amount_fx' => $gstFx->toString(),
            'total_amount_fx' => $totalFx->toString(),
            'amount_paid_fx' => '0.00',
            'credited_fx' => '0.00',
        ]);
        $invoice->save();

        // Stock moves only once the invoice exists, so every movement
        // can reference it -- and because the caller wraps this in a
        // transaction, a refusal on line 3 unwinds lines 1 and 2 and
        // the invoice with them.
        foreach ($prepared as $row) {
            $knownUnitCost = $row['known_unit_cost'];
            unset($row['known_unit_cost']);
            if ($row['stock_item_id'] === null && $knownUnitCost !== null) {
                $row['unit_cost_sgd'] = $knownUnitCost->toString();
                $row['cost_amount_sgd'] = $knownUnitCost->multipliedBy($row['quantity'])->quantize()->toString();
                $cost = $cost->plus(Money::of($row['cost_amount_sgd']));
                $anyCostKnown = true;
            }
            if ($row['stock_item_id'] !== null) {
                if ($row['warehouse_id'] === null) {
                    throw new BillingRuleViolation(
                        "Line {$row['line_no']} picks stock but names no warehouse to issue it from."
                    );
                }
                $movement = InventoryService::deductStock(
                    $companyId,
                    $row['stock_item_id'],
                    $row['warehouse_id'],
                    $row['quantity'],
                    StockMovement::TYPE_ISSUE,
                    referenceType: 'invoice',
                    referenceId: $invoice->id,
                    userId: $actorUserId,
                    notes: $invoice->invoice_number,
                );
                $row['unit_cost_sgd'] = (string) $movement->unit_cost;
                $row['cost_amount_sgd'] = Money::of($movement->total_cost)->toString();
                $cost = $cost->plus(Money::of($row['cost_amount_sgd']));
                $anyCostKnown = true;
            }

            $invoice->lines()->create($row);
        }

        // Left null when no line moved stock: an invoice of pure
        // services has no known cost, and null is what the Sales GP
        // report reads as "no cost basis" rather than "cost was zero".
        if ($anyCostKnown) {
            $invoice->cost_sgd = $cost->toString();
            $invoice->save();
        }

        $invoice->refresh(); // pick up issued_at's DB default before posting
        Posting::postInvoice($invoice, $actorUserId);

        Audit::record(
            entityType: 'invoice',
            entityId: $invoice->id,
            action: 'issued',
            actorUserId: $actorUserId,
            details: "{$invoice->invoice_number}, invoice_type=sales, ".count($prepared).' line(s)',
            newValue: [
                'invoice_number' => $invoice->invoice_number,
                'net_sgd' => (string) $invoice->amount_sgd,
                'gst_sgd' => (string) $invoice->gst_amount_sgd,
                'total_sgd' => (string) $invoice->total_amount_sgd,
                'cost_sgd' => $invoice->cost_sgd !== null ? (string) $invoice->cost_sgd : null,
            ],
        );

        return $invoice;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private static function describeLines(array $lines): string
    {
        $first = $lines[0]['description'];
        $rest = count($lines) - 1;

        return $rest > 0 ? "{$first} (+{$rest} more)" : $first;
    }

    /** SRV-008: contract value divided by contracted hours. */
    public static function blendedRatePerHour(Contract $contract): Money
    {
        $contractedHours = Money::of($contract->contracted_minutes)->dividedBy(60);

        return Money::of($contract->contract_value_sgd)->dividedBy($contractedHours->toFloat())->quantize();
    }

    /**
     * SRV-008: billable excess usage is charged at the contract's own
     * blended rate, no customer pre-approval required.
     */
    public static function issueExcessUsageInvoice(ExcessUsageRecord $excessRecord, Contract $contract, string $actorUserId): Invoice
    {
        $rate = self::blendedRatePerHour($contract);
        $excessHours = Money::of($excessRecord->excess_minutes)->dividedBy(60);
        // multipliedByMoney(), not multipliedBy($excessHours->toFloat()) --
        // excess_hours is often a repeating decimal (e.g. 40/60), and
        // toFloat() would round it to 2dp before the multiply, same as
        // Python's `rate * excess_hours` keeps both sides at full
        // Decimal precision until the final quantize() below.
        $amount = $rate->multipliedByMoney($excessHours)->quantize();

        $invoice = self::buildInvoice(
            companyId: $contract->company_id,
            customerId: $contract->customer_id,
            invoiceType: Invoice::TYPE_EXCESS_USAGE,
            description: sprintf(
                'Excess support usage beyond contracted hours (%s hrs @ SGD %s/hr)',
                $excessHours->toString(), $rate->toString(),
            ),
            netAmount: $amount,
            contractId: $contract->id,
            excessUsageRecordId: $excessRecord->id,
        );
        $invoice->save();
        $invoice->refresh(); // pick up issued_at's DB default before posting
        $excessRecord->invoiced = true;
        $excessRecord->save();

        // ACC-001/003: post on issue -- Dr AR / Cr 4010 excess usage
        // revenue / Cr GST output.
        Posting::postInvoice($invoice, $actorUserId);

        Audit::record(
            entityType: 'invoice',
            entityId: $invoice->id,
            action: 'issued',
            actorUserId: $actorUserId,
            details: "{$invoice->invoice_number}, invoice_type=excess_usage, excess_usage_record_id={$excessRecord->id}",
            newValue: [
                'invoice_number' => $invoice->invoice_number,
                'net_sgd' => (string) $invoice->amount_sgd,
                'gst_sgd' => (string) $invoice->gst_amount_sgd,
                'total_sgd' => (string) $invoice->total_amount_sgd,
            ],
        );

        return $invoice;
    }
}
