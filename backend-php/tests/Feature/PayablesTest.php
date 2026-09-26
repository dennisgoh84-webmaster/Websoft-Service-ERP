<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of PurchaseOrderController/SupplierInvoiceController/
 * AccountsPayableController, mirroring the PO/bill/aging endpoints of
 * backend/app/routers/payables.py. See docs/php-conversion-plan.md's
 * "after converting each module" checklist. Business-rule arithmetic
 * is covered separately in PayablesServiceTest.php.
 */
class PayablesTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): array
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return [$owner, $login->json('access_token')];
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function supplier(Company $company): CompanyIndividual
    {
        return CompanyIndividual::factory()->for($company)->create(['is_supplier' => true]);
    }

    public function test_owner_can_create_and_approve_a_purchase_order(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company);

        $create = $this->postJson('/api/accounts-payable/purchase-orders', [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'description' => 'Laptops', 'amount_sgd' => 5000,
        ], $this->headers($token));

        $create->assertOk();
        $this->assertStringStartsWith('PO-', $create->json('po_number'));
        // No threshold configured -> even the owner's own PO is created
        // pending_approval, per PUR-001's undecided-threshold default.
        $this->assertSame('pending_approval', $create->json('status'));

        $approve = $this->postJson("/api/accounts-payable/purchase-orders/{$create->json('id')}/approve", [], $this->headers($token));
        $approve->assertOk()->assertJson(['status' => 'approved']);
    }

    public function test_creating_a_po_for_a_non_supplier_is_rejected(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $notASupplier = CompanyIndividual::factory()->for($company)->create(['is_supplier' => false]);

        $this->postJson('/api/accounts-payable/purchase-orders', [
            'supplier_id' => $notASupplier->id, 'order_date' => now()->toDateString(),
            'description' => 'x', 'amount_sgd' => 100,
        ], $this->headers($token))->assertStatus(404);
    }

    public function test_import_to_ap_creates_a_matched_and_auto_approved_bill(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company);
        $po = PurchaseOrder::factory()->for($company)->create([
            'supplier_id' => $supplier->id, 'status' => PurchaseOrder::STATUS_APPROVED,
        ]);

        $response = $this->postJson("/api/accounts-payable/purchase-orders/{$po->id}/import-to-ap", [], $this->headers($token));

        $response->assertOk()->assertJson(['match_status' => 'matched', 'status' => 'approved']);
        $this->assertStringStartsWith('BILL-', $response->json('bill_number'));
    }

    public function test_cannot_import_the_same_po_twice(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $po = PurchaseOrder::factory()->for($company)->create(['status' => PurchaseOrder::STATUS_APPROVED]);
        $this->postJson("/api/accounts-payable/purchase-orders/{$po->id}/import-to-ap", [], $this->headers($token));

        $this->postJson("/api/accounts-payable/purchase-orders/{$po->id}/import-to-ap", [], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_owner_can_record_a_standalone_bill_with_no_po(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company);

        $this->purchaseCodes($company);

        $response = $this->postJson('/api/accounts-payable/bills', [
            'supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(),
            'description' => 'Ad-hoc consulting', 'amount_sgd' => 500,
        ], $this->headers($token));

        $response->assertOk()->assertJson(['match_status' => 'not_matched', 'status' => 'awaiting_match', 'tax_code' => 'TX', 'gst_rate' => 9]);
        $this->assertEqualsWithDelta(545.0, $response->json('total_amount_sgd'), 0.01);
    }

    public function test_a_bills_gst_is_worked_out_from_its_purchase_tax_code_like_a_sales_invoice(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company);
        $this->purchaseCodes($company);
        $bill = fn (array $extra) => $this->postJson('/api/accounts-payable/bills', [
            'supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(), 'description' => 'x', 'amount_sgd' => 333.33,
        ] + $extra, $this->headers($token));

        // Standard-rated: 9% of 333.33 = 30.00 (half up), whatever GST is typed.
        $bill(['tax_code' => 'tx', 'gst_amount_sgd' => 1])->assertOk()
            ->assertJson(['tax_code' => 'TX', 'gst_amount_sgd' => 30, 'total_amount_sgd' => 363.33]);
        $bill(['tax_code' => 'NR'])->assertOk()->assertJson(['tax_code' => 'NR', 'gst_amount_sgd' => 0]);
        // A sales code is not a purchase code.
        $bill(['tax_code' => 'SR'])->assertStatus(422);
    }

    private function purchaseCodes(Company $company): void
    {
        TaxCode::create(['company_id' => $company->id, 'code' => 'SR', 'name' => 'Standard-rated supply', 'rate_percent' => 9, 'kind' => TaxCode::KIND_SUPPLY]);
        foreach (['TX' => 9, 'ZP' => 0, 'EP' => 0, 'OP' => 0, 'NR' => 0] as $code => $rate) {
            TaxCode::create(['company_id' => $company->id, 'code' => $code, 'name' => $code, 'rate_percent' => $rate, 'kind' => TaxCode::KIND_PURCHASE]);
        }
    }

    public function test_an_old_amount_exception_can_be_matched_again_and_becomes_payable(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $po = PurchaseOrder::factory()->for($company)->create(['status' => PurchaseOrder::STATUS_APPROVED, 'total_amount_sgd' => 1090]);
        // Flagged under the rule before 4.5 was settled.
        $bill = SupplierInvoice::factory()->for($company)->create([
            'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id,
            'amount_sgd' => 2000, 'gst_amount_sgd' => 180, 'total_amount_sgd' => 2180,
            'match_status' => SupplierInvoice::MATCH_EXCEPTION, 'status' => SupplierInvoice::STATUS_EXCEPTION,
            'match_note' => 'PO total is SGD 1090.00 but the bill is SGD 2180.00.',
        ]);

        $this->postJson("/api/accounts-payable/bills/{$bill->id}/rematch", [], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'approved', 'match_status' => 'matched']);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'supplier_invoice', 'entity_id' => $bill->id, 'action' => 'rematched']);

        // Only an exception can be matched again.
        $this->postJson("/api/accounts-payable/bills/{$bill->id}/rematch", [], $this->headers($token))->assertStatus(409);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounts-payable/purchase-orders', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_po_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        $poB = PurchaseOrder::factory()->for($companyB)->create();

        $this->getJson("/api/accounts-payable/purchase-orders/{$poB->id}", $this->headers($tokenA))->assertStatus(404);
    }

    public function test_ap_aging_report_returns_outstanding_bills(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $supplier = $this->supplier($company);
        $this->purchaseCodes($company);
        $this->postJson('/api/accounts-payable/bills', [
            'supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(),
            'description' => 'x', 'amount_sgd' => 1000, 'tax_code' => 'NR',
        ], $this->headers($token))->assertOk();

        $response = $this->getJson('/api/accounts-payable/aging', $this->headers($token));

        $response->assertOk();
        $this->assertCount(1, $response->json('rows'));
        $this->assertEqualsWithDelta(1000.0, $response->json('rows.0.current'), 0.01);
    }
}
