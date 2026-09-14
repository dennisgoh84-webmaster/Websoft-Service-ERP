<?php

namespace App\Services;

use App\Exceptions\PayablesRuleViolation;
use App\Exceptions\PostingError;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Accounts Payable business logic. Mirrors
 * backend/app/services/payables.py -- see that file's docstring for
 * PUR-001/002/003. A matched bill (PUR-003 auto-approval) posts to
 * the GL in the same step (Dr expense / Dr GST input / Cr AP,
 * ACC-001/003, App\Services\Posting), now that GL posting is
 * converted -- see matchBillToPo().
 */
class PayablesService
{
    /**
     * PUR-001. With no threshold set (open item 4.4), everything needs
     * the owner -- the safe reading of an undecided rule.
     */
    public static function poNeedsOwnerApproval(string $companyId, Money $amount): bool
    {
        $company = Company::find($companyId);
        $threshold = $company?->po_approval_threshold_sgd;
        if ($threshold === null) {
            return true;
        }

        return $amount->toFloat() > Money::of($threshold)->toFloat();
    }

    /** PUR-001: approve a PO, respecting the value threshold. */
    public static function approvePurchaseOrder(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status === PurchaseOrder::STATUS_APPROVED) {
            throw new PayablesRuleViolation('That purchase order is already approved.');
        }
        if ($po->status === PurchaseOrder::STATUS_CANCELLED) {
            throw new PayablesRuleViolation('That purchase order was cancelled.');
        }

        if (self::poNeedsOwnerApproval($po->company_id, Money::of($po->total_amount_sgd))) {
            if ($actor->role !== User::ROLE_OWNER) {
                $company = Company::find($po->company_id);
                $threshold = $company?->po_approval_threshold_sgd;
                if ($threshold === null) {
                    throw new PayablesRuleViolation(
                        'No purchase order approval threshold has been set, so every PO needs '.
                        "the owner's approval (PUR-001). Set a threshold in Company Setup, or ".
                        'ask the owner to approve this.'
                    );
                }
                throw new PayablesRuleViolation(sprintf(
                    'SGD %s is above the SGD %s approval threshold -- the owner must approve this purchase order (PUR-001).',
                    Money::of($po->total_amount_sgd)->toString(), Money::of($threshold)->toString(),
                ));
            }
        }

        $po->status = PurchaseOrder::STATUS_APPROVED;
        $po->approved_by_user_id = $actor->id;
        $po->approved_at = Carbon::now();
        $po->save();

        return $po;
    }

    /**
     * Guard for "confirm and import to AP" (2026-09-12): a PO must be
     * confirmed (PUR-001 approved) before it becomes a bill, and each
     * PO can only be imported once -- re-importing would double the AP
     * liability for the same spend.
     */
    public static function assertPoImportableToAp(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrder::STATUS_APPROVED) {
            throw new PayablesRuleViolation(sprintf(
                '%s is %s, not approved -- approve it first (PUR-001) before importing it to Accounts Payable.',
                $po->po_number, str_replace('_', ' ', $po->status),
            ));
        }
        $firstBill = $po->bills()->first();
        if ($firstBill !== null) {
            throw new PayablesRuleViolation(sprintf(
                '%s was already imported to Accounts Payable as %s.',
                $po->po_number, $firstBill->bill_number,
            ));
        }
    }

    /**
     * PUR-002 2-way match, and PUR-003's consequence. Compares the bill
     * to its purchase order. Agreement auto-approves it for payment;
     * disagreement records exactly what differs and leaves it as an
     * exception for a human -- resolution is open item 4.5.
     */
    public static function matchBillToPo(SupplierInvoice $bill, ?string $actorUserId = null): SupplierInvoice
    {
        if ($bill->purchase_order_id === null) {
            // No PO to match against. Not an error -- some spend
            // legitimately has no purchase order -- but it cannot be
            // auto-approved under PUR-003 either, so it waits for a
            // person.
            $bill->match_status = SupplierInvoice::MATCH_NOT_MATCHED;
            $bill->match_note = 'No purchase order referenced, so 2-way matching does not apply.';
            $bill->status = SupplierInvoice::STATUS_AWAITING_MATCH;
            $bill->save();

            return $bill;
        }

        $po = PurchaseOrder::find($bill->purchase_order_id);
        if ($po === null || $po->company_id !== $bill->company_id) {
            throw new PayablesRuleViolation('The referenced purchase order does not exist.');
        }

        $problems = [];
        if ($po->supplier_id !== $bill->supplier_id) {
            $problems[] = 'the bill is from a different supplier than the purchase order';
        }
        if (Money::of($po->total_amount_sgd)->toFloat() !== Money::of($bill->total_amount_sgd)->toFloat()) {
            $problems[] = sprintf(
                'PO total is SGD %s but the bill is SGD %s',
                Money::of($po->total_amount_sgd)->toString(), Money::of($bill->total_amount_sgd)->toString(),
            );
        }
        if ($po->status !== PurchaseOrder::STATUS_APPROVED) {
            $problems[] = "the purchase order is {$po->status}, not approved";
        }

        if ($problems !== []) {
            $note = implode('; ', $problems);
            $bill->match_status = SupplierInvoice::MATCH_EXCEPTION;
            $bill->match_note = ucfirst($note).'.';
            $bill->status = SupplierInvoice::STATUS_EXCEPTION;
            $bill->save();

            return $bill;
        }

        // PUR-002 satisfied -> PUR-003 auto-approves it for payment.
        $bill->match_status = SupplierInvoice::MATCH_MATCHED;
        $bill->match_note = "Matched to {$po->po_number} (2-way, PUR-002); auto-approved (PUR-003).";
        $bill->status = SupplierInvoice::STATUS_APPROVED;
        $bill->save();

        // ACC-001/003: reaching `approved` is the bill's accounting
        // event -- post it now (Dr expense / Dr GST input / Cr AP). A
        // bill still in awaiting_match or exception never gets here, so
        // an unresolved mismatch never sits in AP (gl-posting-design.md
        // §4.2).
        try {
            Posting::postSupplierInvoice($bill, $actorUserId);
        } catch (PostingError $e) {
            throw new PayablesRuleViolation($e->getMessage());
        }

        return $bill;
    }

    /**
     * Derive a bill's paid state from what is allocated to it, so it
     * can't drift out of step with the money actually paid.
     */
    public static function recalculateBillStatus(SupplierInvoice $bill): void
    {
        $paid = SupplierPaymentAllocation::where('supplier_invoice_id', $bill->id)->get()
            ->reduce(fn (Money $carry, SupplierPaymentAllocation $a) => $carry->plus(Money::of($a->amount_sgd)), Money::of(0));
        $bill->amount_paid_sgd = $paid->toString();

        if ($bill->status === SupplierInvoice::STATUS_EXCEPTION && $paid->toFloat() <= 0) {
            $bill->save();

            return; // an unresolved exception stays an exception
        }

        $total = Money::of($bill->total_amount_sgd);
        if ($paid->toFloat() <= 0) {
            $bill->status = $bill->match_status === SupplierInvoice::MATCH_MATCHED
                ? SupplierInvoice::STATUS_APPROVED : SupplierInvoice::STATUS_AWAITING_MATCH;
        } elseif ($paid->toFloat() >= $total->toFloat()) {
            $bill->status = SupplierInvoice::STATUS_PAID;
        } else {
            $bill->status = SupplierInvoice::STATUS_PARTIALLY_PAID;
        }
        $bill->save();
    }

    /** Apply part of a payment voucher to one bill. */
    public static function allocateSupplierPayment(SupplierPayment $payment, SupplierInvoice $bill, Money $amount): SupplierPaymentAllocation
    {
        if ($amount->toFloat() <= 0) {
            throw new PayablesRuleViolation('Allocation amount must be greater than zero.');
        }
        if ($bill->company_id !== $payment->company_id) {
            throw new PayablesRuleViolation('Bill and payment belong to different companies.');
        }
        if ($bill->supplier_id !== $payment->supplier_id) {
            throw new PayablesRuleViolation('That bill belongs to a different supplier than this payment.');
        }
        if ($bill->status === SupplierInvoice::STATUS_EXCEPTION) {
            throw new PayablesRuleViolation("{$bill->bill_number} is a matching exception and is not approved for payment (PUR-003). Resolve the mismatch first.");
        }
        if ($amount->toFloat() > $payment->unallocatedSgd()->toFloat()) {
            throw new PayablesRuleViolation("Only SGD {$payment->unallocatedSgd()->toString()} of this payment is still unallocated.");
        }
        if ($amount->toFloat() > $bill->outstandingSgd()->toFloat()) {
            throw new PayablesRuleViolation("Bill {$bill->bill_number} only has SGD {$bill->outstandingSgd()->toString()} outstanding.");
        }

        $allocation = SupplierPaymentAllocation::create([
            'company_id' => $payment->company_id,
            'payment_id' => $payment->id,
            'supplier_invoice_id' => $bill->id,
            'amount_sgd' => $amount->toString(),
        ]);
        self::recalculateBillStatus($bill);

        return $allocation;
    }

    /** From the supplier's agreed terms. None when none are agreed -- same treatment customers get. */
    public static function dueDateForBill(string $supplierId, Carbon $invoiceDate): ?Carbon
    {
        $supplier = CompanyIndividual::find($supplierId);
        if (! $supplier || $supplier->payment_terms_days === null) {
            return null;
        }

        return $invoiceDate->copy()->addDays((int) $supplier->payment_terms_days);
    }

    /**
     * What we owe suppliers, bucketed by how far past due it is.
     * Mirrors backend/app/routers/payables.py's _ap_aging_rows, reusing
     * AccountsReceivableService's bucket boundaries (aging_bucket_for
     * is shared with AR in the Python source too).
     *
     * @return array{0: Carbon, 1: array<int, array{supplier_id: string, supplier_name: string, current: float, days_1_30: float, days_31_60: float, days_61_90: float, over_90: float, total: float}>}
     */
    public static function agingRows(string $companyId, ?Carbon $asAt = null): array
    {
        $asAt = $asAt ?? Carbon::today();
        $bills = SupplierInvoice::where('company_id', $companyId)
            ->where('status', '!=', SupplierInvoice::STATUS_PAID)
            ->get();
        $supplierNames = CompanyIndividual::where('company_id', $companyId)->pluck('name', 'id');

        $buckets = [];
        foreach ($bills as $bill) {
            $outstanding = $bill->outstandingSgd();
            if ($outstanding->toFloat() <= 0) {
                continue;
            }
            $supplierId = $bill->supplier_id;
            if (! isset($buckets[$supplierId])) {
                $buckets[$supplierId] = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
            }
            $bucket = AccountsReceivableService::agingBucketFor($bill->due_date, $asAt);
            $buckets[$supplierId][$bucket] += $outstanding->toFloat();
        }

        $rows = [];
        foreach ($buckets as $supplierId => $b) {
            $rows[] = [
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierNames->get($supplierId, '(unknown)'),
                'current' => $b['current'],
                'days_1_30' => $b['1_30'],
                'days_31_60' => $b['31_60'],
                'days_61_90' => $b['61_90'],
                'over_90' => $b['over_90'],
                'total' => array_sum($b),
            ];
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [$asAt, $rows];
    }
}
