<?php

namespace Tests\Feature;

use App\Exceptions\PayablesRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\PayablesService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\PayablesService, mirroring
 * backend/app/services/payables.py's PUR-001/002/003 rules exactly.
 * See docs/php-conversion-plan.md's "after converting each module"
 * checklist.
 */
class PayablesServiceTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(Company $company): CompanyIndividual
    {
        return CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
    }

    // ---- PUR-001: PO approval ---------------------------------------------

    public function test_owner_approval_required_when_the_supplier_has_no_limit(): void
    {
        $supplier = $this->supplier(Company::factory()->create());
        $this->assertTrue(PayablesService::poNeedsOwnerApproval($supplier->id, Money::of(1)));
    }

    public function test_below_the_suppliers_limit_does_not_need_owner(): void
    {
        $supplier = $this->supplier(Company::factory()->create());
        $supplier->update(['po_approval_limit_sgd' => 5000]);
        $this->assertFalse(PayablesService::poNeedsOwnerApproval($supplier->id, Money::of(3000)));
        $this->assertFalse(PayablesService::poNeedsOwnerApproval($supplier->id, Money::of(5000)));
    }

    public function test_above_the_suppliers_limit_needs_owner(): void
    {
        $supplier = $this->supplier(Company::factory()->create());
        $supplier->update(['po_approval_limit_sgd' => 5000]);
        $this->assertTrue(PayablesService::poNeedsOwnerApproval($supplier->id, Money::of(5001)));
    }

    public function test_each_supplier_carries_its_own_limit(): void
    {
        $company = Company::factory()->create();
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $trusted = $this->supplier($company);
        $trusted->update(['po_approval_limit_sgd' => 5000]);
        $other = $this->supplier($company);
        $poTrusted = PurchaseOrder::factory()->create(['company_id' => $company->id, 'supplier_id' => $trusted->id, 'total_amount_sgd' => 1000]);
        $poOther = PurchaseOrder::factory()->create(['company_id' => $company->id, 'supplier_id' => $other->id, 'total_amount_sgd' => 1000]);

        PayablesService::approvePurchaseOrder($poTrusted, $staff);
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $poTrusted->fresh()->status);

        try {
            PayablesService::approvePurchaseOrder($poOther, $staff);
            $this->fail('A PO to a supplier with no limit was approved by a non-owner');
        } catch (PayablesRuleViolation $e) {
            $this->assertStringContainsString('No purchase order approval limit is set for', $e->getMessage());
        }
    }

    public function test_non_owner_cannot_approve_when_owner_required(): void
    {
        $company = Company::factory()->create();
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $po = PurchaseOrder::factory()->for($company)->create();

        $this->expectException(PayablesRuleViolation::class);
        PayablesService::approvePurchaseOrder($po, $staff);
    }

    public function test_owner_can_always_approve(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $po = PurchaseOrder::factory()->for($company)->create();

        PayablesService::approvePurchaseOrder($po, $owner);

        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $po->fresh()->status);
        $this->assertSame($owner->id, $po->fresh()->approved_by_user_id);
    }

    public function test_non_owner_can_approve_below_the_suppliers_limit(): void
    {
        $company = Company::factory()->create();
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $supplier = $this->supplier($company);
        $supplier->update(['po_approval_limit_sgd' => 5000]);
        $po = PurchaseOrder::factory()->create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'total_amount_sgd' => 1000]);

        PayablesService::approvePurchaseOrder($po, $staff);

        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $po->fresh()->status);
    }

    public function test_cannot_approve_an_already_approved_po(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $po = PurchaseOrder::factory()->for($company)->create(['status' => PurchaseOrder::STATUS_APPROVED]);

        $this->expectException(PayablesRuleViolation::class);
        PayablesService::approvePurchaseOrder($po, $owner);
    }

    public function test_cannot_approve_a_cancelled_po(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER]);
        $po = PurchaseOrder::factory()->for($company)->create(['status' => PurchaseOrder::STATUS_CANCELLED]);

        $this->expectException(PayablesRuleViolation::class);
        PayablesService::approvePurchaseOrder($po, $owner);
    }

    // ---- Import to AP -------------------------------------------------------

    public function test_cannot_import_an_unapproved_po(): void
    {
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create();

        $this->expectException(PayablesRuleViolation::class);
        PayablesService::assertPoImportableToAp($po);
    }

    public function test_cannot_import_a_po_twice(): void
    {
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create(['status' => PurchaseOrder::STATUS_APPROVED]);
        SupplierInvoice::factory()->for($company)->create(['purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id]);

        $this->expectException(PayablesRuleViolation::class);
        PayablesService::assertPoImportableToAp($po);
    }

    // ---- PUR-002/003: 2-way matching ----------------------------------------

    public function test_bill_with_no_po_is_not_matched_and_awaits_a_human(): void
    {
        $company = Company::factory()->create();
        $bill = SupplierInvoice::factory()->for($company)->create(['purchase_order_id' => null]);

        PayablesService::matchBillToPo($bill);

        $this->assertSame(SupplierInvoice::MATCH_NOT_MATCHED, $bill->fresh()->match_status);
        $this->assertSame(SupplierInvoice::STATUS_AWAITING_MATCH, $bill->fresh()->status);
    }

    public function test_bill_matching_an_approved_po_auto_approves(): void
    {
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create([
            'status' => PurchaseOrder::STATUS_APPROVED, 'total_amount_sgd' => 1090,
        ]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'total_amount_sgd' => 1090,
        ]);

        PayablesService::matchBillToPo($bill);

        $this->assertSame(SupplierInvoice::MATCH_MATCHED, $bill->fresh()->match_status);
        $this->assertSame(SupplierInvoice::STATUS_APPROVED, $bill->fresh()->status);
    }

    public function test_a_bill_for_more_than_the_po_is_approved_and_paid_on_the_billed_amount(): void
    {
        // Dennis, 2026-09-26 (4.5): ordered 10, 20 delivered, billed for 20 -- pay the 20.
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create([
            'status' => PurchaseOrder::STATUS_APPROVED, 'total_amount_sgd' => 1090,
        ]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id,
            'amount_sgd' => 2000, 'gst_amount_sgd' => 180, 'total_amount_sgd' => 2180,
        ]);

        PayablesService::matchBillToPo($bill);

        $bill = $bill->fresh();
        $this->assertSame(SupplierInvoice::MATCH_MATCHED, $bill->match_status);
        $this->assertSame(SupplierInvoice::STATUS_APPROVED, $bill->status, 'cleared for payment');
        $this->assertStringContainsString('The bill (SGD 2180.00) differs from the purchase order (SGD 1090.00)', $bill->match_note);
        $this->assertSame('2180.00', $bill->outstandingSgd()->toString(), 'payable in full, on the billed amount');
    }

    public function test_bill_against_an_unapproved_po_becomes_an_exception(): void
    {
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create(['total_amount_sgd' => 1090]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'total_amount_sgd' => 1090,
        ]);

        PayablesService::matchBillToPo($bill);

        $this->assertSame(SupplierInvoice::STATUS_EXCEPTION, $bill->fresh()->status);
    }

    public function test_bill_from_a_different_supplier_than_the_po_becomes_an_exception(): void
    {
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create([
            'status' => PurchaseOrder::STATUS_APPROVED, 'total_amount_sgd' => 1090,
        ]);
        $otherSupplier = $this->supplier($company);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'purchase_order_id' => $po->id, 'supplier_id' => $otherSupplier->id, 'total_amount_sgd' => 1090,
        ]);

        PayablesService::matchBillToPo($bill);

        $this->assertSame(SupplierInvoice::STATUS_EXCEPTION, $bill->fresh()->status);
        $this->assertStringContainsString('different supplier', $bill->fresh()->match_note);
    }

    // ---- Aging ----------------------------------------------------------

    public function test_aging_rows_exclude_paid_bills(): void
    {
        $company = Company::factory()->create();
        SupplierInvoice::factory()->for($company)->create([
            'status' => SupplierInvoice::STATUS_PAID, 'total_amount_sgd' => 500, 'amount_paid_sgd' => 500,
        ]);

        [, $rows] = PayablesService::agingRows($company->id);

        $this->assertCount(0, $rows);
    }

    public function test_aging_rows_bucket_by_supplier(): void
    {
        $company = Company::factory()->create();
        $bill = SupplierInvoice::factory()->for($company)->create(['total_amount_sgd' => 1000]);
        $bill->update(['due_date' => now()->subDays(10)->toDateString()]);

        [, $rows] = PayablesService::agingRows($company->id);

        $this->assertCount(1, $rows);
        $this->assertSame($bill->supplier_id, $rows[0]['supplier_id']);
        $this->assertEqualsWithDelta(1000.0, $rows[0]['days_1_30'], 0.01);
    }
}
