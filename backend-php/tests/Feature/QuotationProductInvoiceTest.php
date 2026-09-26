<?php

namespace Tests\Feature;

use App\Exceptions\QuotationRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PasswordPolicy;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Decision 11.2 (Dennis, 2026-09-26): accepting a quotation issues a
 * Sales Invoice for its product lines straight away -- lines whose
 * catalog product is a Product -- taking stock from the warehouse picked
 * on Accept; hour and other service lines still become contracts.
 */
class QuotationProductInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $customer;

    private Warehouse $warehouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $this->customer = CompanyIndividual::factory()->for($this->company)->create();
        $this->warehouse = Warehouse::factory()->for($this->company)->create();
        TaxCode::create(['company_id' => $this->company->id, 'code' => 'SR', 'name' => 'Standard-rated', 'rate_percent' => 9, 'is_active' => true]);
    }

    private function quotation(array $lines, array $extra = []): Quotation
    {
        $q = Quotation::factory()->create(['status' => Quotation::STATUS_SENT, 'company_id' => $this->company->id, 'customer_id' => $this->customer->id] + $extra);
        foreach ($lines as $l) {
            QuotationLine::create(['quotation_id' => $q->id, 'line_total_sgd' => $l['quantity'] * $l['unit_price_sgd']] + $l);
        }

        return $q->load('lines');
    }

    private function product(string $type, ?int $stocked = null): Product
    {
        $p = Product::factory()->create(['company_id' => $this->company->id, 'product_type' => $type]);
        if ($stocked !== null) {
            $item = StockItem::factory()->for($this->company)->create(['product_id' => $p->id]);
            InventoryService::receiveStock($this->company->id, $item->id, $this->warehouse->id, $stocked, '40.0000',
                referenceType: 'test', referenceId: (string) Str::orderedUuid());
        }

        return $p;
    }

    public function test_product_lines_become_a_sales_invoice_issued_on_acceptance_and_services_a_contract(): void
    {
        $router = $this->product(Product::TYPE_PRODUCT, stocked: 5);
        $licence = $this->product(Product::TYPE_PRODUCT);
        $support = $this->product(Product::TYPE_SERVICE);
        $prospect = Prospect::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id]);
        $q = $this->quotation([
            ['description' => 'Router', 'product_id' => $router->id, 'quantity' => 2, 'unit_price_sgd' => 100, 'cost_sgd' => 55],
            ['description' => 'Licence', 'product_id' => $licence->id, 'quantity' => 1, 'unit_price_sgd' => 300, 'cost_sgd' => 120],
            ['description' => 'Support hours', 'product_id' => $support->id, 'unit_of_measure' => 'Hours', 'quantity' => 12, 'unit_price_sgd' => 100],
            ['description' => 'Setup fee', 'quantity' => 1, 'unit_price_sgd' => 150],
        ], ['prospect_id' => $prospect->id]);

        $message = QuotationService::acceptQuotation($q, $this->owner->id, $this->warehouse->id);
        $q->save();

        $invoice = Invoice::with('lines')->findOrFail($q->converted_invoice_id);
        $this->assertSame(Invoice::TYPE_SALES, $invoice->invoice_type);
        $this->assertSame('500.00', (string) $invoice->amount_sgd); // 2 x 100 + 300; services are not on it
        $this->assertSame($prospect->id, $invoice->prospect_id);
        $this->assertCount(2, $invoice->lines);
        // Stock line costed at the stock's average (2 x 40); the licence at the quotation's cost (120).
        $this->assertSame('200.00', (string) $invoice->cost_sgd);
        $this->assertSame(3, (int) StockLevel::where('warehouse_id', $this->warehouse->id)->value('quantity'));
        // The hours still make a Service Support contract, and the setup fee an Annual one.
        $this->assertNotNull($q->converted_contract_id);
        $this->assertNotNull($q->converted_annual_contract_id);
        $this->assertStringContainsString($invoice->invoice_number, $message);
    }

    public function test_a_stock_line_needs_a_warehouse_and_enough_stock_or_nothing_is_accepted(): void
    {
        $router = $this->product(Product::TYPE_PRODUCT, stocked: 1);
        $q = $this->quotation([['description' => 'Router', 'product_id' => $router->id, 'quantity' => 2, 'unit_price_sgd' => 100]]);

        try {
            QuotationService::acceptQuotation($q, $this->owner->id);
            $this->fail('accepted without a warehouse');
        } catch (QuotationRuleViolation $e) {
            $this->assertStringContainsString('warehouse', $e->getMessage());
        }

        $h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $this->owner->email, 'password' => 'demo1234'])->json('access_token')];
        $this->postJson("/api/quotations/{$q->id}/accept", ['warehouse_id' => $this->warehouse->id], $h)->assertStatus(409);
        $this->assertSame(Quotation::STATUS_SENT, $q->fresh()->status);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, (int) StockLevel::where('warehouse_id', $this->warehouse->id)->value('quantity'));
    }

    public function test_a_part_quantity_on_a_product_line_is_refused(): void
    {
        $licence = $this->product(Product::TYPE_PRODUCT);
        $q = $this->quotation([['description' => 'Licence', 'product_id' => $licence->id, 'quantity' => 1.5, 'unit_price_sgd' => 300]]);

        $this->expectException(QuotationRuleViolation::class);
        QuotationService::acceptQuotation($q, $this->owner->id);
    }

    public function test_the_api_accepts_with_a_warehouse_and_says_which_lines_need_one(): void
    {
        $router = $this->product(Product::TYPE_PRODUCT, stocked: 5);
        $q = $this->quotation([['description' => 'Router', 'product_id' => $router->id, 'quantity' => 1, 'unit_price_sgd' => 100]]);
        $h = ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $this->owner->email, 'password' => 'demo1234'])->json('access_token')];

        $this->getJson("/api/quotations/{$q->id}", $h)->assertOk()
            ->assertJsonPath('lines.0.is_product_line', true)->assertJsonPath('lines.0.is_stock_line', true);
        $other = Warehouse::factory()->for(Company::factory())->create();
        $this->postJson("/api/quotations/{$q->id}/accept", ['warehouse_id' => $other->id], $h)->assertStatus(422);

        $r = $this->postJson("/api/quotations/{$q->id}/accept", ['warehouse_id' => $this->warehouse->id], $h)->assertOk();
        $this->assertNotNull($r->json('quotation.converted_invoice_number'));
        $this->assertNull($r->json('quotation.converted_annual_contract_id'));
    }
}
