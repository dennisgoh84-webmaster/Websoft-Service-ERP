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

    public function test_owner_approval_required_when_no_threshold_is_set(): void
    {
        $company = Company::factory()->create();
        $this->assertTrue(PayablesService::poNeedsOwnerApproval($company->id, Money::of(1)));
    }

    public function test_below_threshold_does_not_need_owner(): void
    {
        $company = Company::factory()->create(['po_approval_threshold_sgd' => 5000]);
        $this->assertFalse(PayablesService::poNeedsOwnerApproval($company->id, Money::of(3000)));
    }

    public function test_above_threshold_needs_owner(): void
    {
        $company = Company::factory()->create(['po_approval_threshold_sgd' => 5000]);
        $this->assertTrue(PayablesService::poNeedsOwnerApproval($company->id, Money::of(5001)));
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

    public function test_non_owner_can_approve_below_threshold(): void
    {
        $company = Company::factory()->create(['po_approval_threshold_sgd' => 5000]);
        $staff = User::factory()->for($company)->create(['role' => User::ROLE_SUPPORT_ENGINEER]);
        $po = PurchaseOrder::factory()->for($company)->create(['total_amount_sgd' => 1000]);

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

    public function test_bill_amount_mismatch_becomes_an_exception(): void
    {
        $company = Company::factory()->create();
        $po = PurchaseOrder::factory()->for($company)->create([
            'status' => PurchaseOrder::STATUS_APPROVED, 'total_amount_sgd' => 1090,
        ]);
        $bill = SupplierInvoice::factory()->for($company)->create([
            'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'total_amount_sgd' => 2000,
        ]);

        PayablesService::matchBillToPo($bill);

        $this->assertSame(SupplierInvoice::MATCH_EXCEPTION, $bill->fresh()->match_status);
        $this->assertSame(SupplierInvoice::STATUS_EXCEPTION, $bill->fresh()->status);
        $this->assertStringContainsString('PO total is SGD 1090.00 but the bill is SGD 2000.00', $bill->fresh()->match_note);
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
