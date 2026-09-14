<?php

namespace App\Services;

use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\Invoice;
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
