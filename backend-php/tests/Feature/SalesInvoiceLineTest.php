<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manually raised Sales Invoices with lines that pick stock.
 *
 * Dennis, 2026-09-15: "Sales invoice or goods issue note not allowed
 * to update whenever qty is insufficient. Do not allow for on hand qty
 * become negative n costing becoming negative. ... same as Sales
 * Invoice when pick stock n update" (deduct at average cost).
 *
 * The rules themselves live in InventoryService and are already
 * covered by the Goods Issue Note tests; what these assert is that
 * the invoice path really goes THROUGH that service -- that it cannot
 * over-issue, cannot leave stock half-deducted behind a refused
 * invoice, and records the average cost as at the moment of issue.
 */
class SalesInvoiceLineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private CompanyIndividual $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
        $this->customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Acme Pte Ltd']);
        $this->warehouse = Warehouse::factory()->for($this->company)->create();
        TaxCode::create([
            'company_id' => $this->company->id, 'code' => 'SR',
            'name' => 'Standard-rated', 'rate_percent' => 9, 'is_active' => true,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /** A stock item holding $qty units, received at $unitCost each. */
    private function stockedItem(int $qty, string $unitCost = '10.0000'): StockItem
    {
        $item = StockItem::factory()->for($this->company)->create();
        InventoryService::receiveStock(
            $this->company->id, $item->id, $this->warehouse->id, $qty, $unitCost,
            referenceType: 'test', referenceId: (string) Str::orderedUuid(),
        );

        return $item->refresh();
    }

    private function onHand(StockItem $item): int
    {
        return (int) StockLevel::where('stock_item_id', $item->id)
            ->where('warehouse_id', $this->warehouse->id)->value('quantity');
    }

    // ── The happy path ──────────────────────────────────────────────

    public function test_a_sales_invoice_picks_stock_and_deducts_at_average_cost(): void
    {
        $item = $this->stockedItem(10, '10.0000');
        $product = Product::factory()->for($this->company)->create(['name' => 'Switch 24-port']);

        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch 24-port',
                'product_id' => $product->id,
                'stock_item_id' => $item->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 4,
                'unit_price_sgd' => 25,
            ]],
        ], $this->headers());

        $response->assertCreated();
        $this->assertSame('sales', $response->json('invoice_type'));
        $this->assertEqualsWithDelta(100, $response->json('amount_sgd'), 0.001);
        // Cost is the weighted average, 4 x 10.00 -- which makes this
        // invoice's gross profit a measured figure, not a stand-in.
        $this->assertEqualsWithDelta(40, $response->json('cost_sgd'), 0.001);
        $this->assertEqualsWithDelta(10, $response->json('lines.0.unit_cost_sgd'), 0.001);
        $this->assertEqualsWithDelta(40, $response->json('lines.0.cost_amount_sgd'), 0.001);
        $this->assertSame(6, $this->onHand($item));

        // The movement is attributed to the invoice, not to a GIN.
        $movement = StockMovement::where('reference_type', 'invoice')->firstOrFail();
        $this->assertSame($response->json('id'), $movement->reference_id);
        $this->assertSame(-4, (int) $movement->quantity);
    }

    public function test_gst_is_applied_once_to_the_summed_lines(): void
    {
        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [
                ['description' => 'Installation', 'quantity' => 2, 'unit_price_sgd' => 300],
                ['description' => 'Cabling', 'quantity' => 1, 'unit_price_sgd' => 400],
            ],
        ], $this->headers());

        $response->assertCreated();
        $this->assertEqualsWithDelta(1000, $response->json('amount_sgd'), 0.001);
        $this->assertEqualsWithDelta(90, $response->json('gst_amount_sgd'), 0.001);
        $this->assertEqualsWithDelta(1090, $response->json('total_amount_sgd'), 0.001);
        $this->assertCount(2, $response->json('lines'));
        $this->assertSame(1, $response->json('lines.0.line_no'));
        $this->assertSame(2, $response->json('lines.1.line_no'));
    }

    public function test_a_service_only_invoice_has_no_cost_basis_rather_than_a_zero_one(): void
    {
        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price_sgd' => 500]],
        ], $this->headers());

        $response->assertCreated();
        // Null, not 0.00: "no known cost" and "cost was nothing" are
        // different, and the Sales GP report distinguishes them.
        $this->assertNull($response->json('cost_sgd'));
        $this->assertNull($response->json('lines.0.unit_cost_sgd'));
    }

    public function test_the_invoice_posts_to_the_general_ledger_on_issue(): void
    {
        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price_sgd' => 500]],
        ], $this->headers());

        $response->assertCreated()->assertJson(['gl_status' => 'posted']);
        $this->assertNotNull($response->json('gl_voucher_number'));
    }

    // ── The rules Dennis asked for ──────────────────────────────────

    public function test_insufficient_stock_refuses_the_whole_invoice(): void
    {
        $item = $this->stockedItem(3);

        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch', 'stock_item_id' => $item->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertStringContainsString('Insufficient stock', $response->json('detail'));
        // Refused outright, never partially issued.
        $this->assertSame(3, $this->onHand($item));
        $this->assertSame(0, Invoice::where('company_id', $this->company->id)->count());
    }

    public function test_a_refused_line_unwinds_the_stock_the_earlier_lines_already_took(): void
    {
        $plenty = $this->stockedItem(10);
        $scarce = $this->stockedItem(1);

        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [
                [
                    'description' => 'Line 1 -- deducts fine', 'stock_item_id' => $plenty->id,
                    'warehouse_id' => $this->warehouse->id, 'quantity' => 2, 'unit_price_sgd' => 25,
                ],
                [
                    'description' => 'Line 2 -- asks for too much', 'stock_item_id' => $scarce->id,
                    'warehouse_id' => $this->warehouse->id, 'quantity' => 9, 'unit_price_sgd' => 25,
                ],
            ],
        ], $this->headers());

        $response->assertStatus(422);
        // The whole thing is one transaction: line 1's deduction is
        // rolled back with the invoice, not left stranded.
        $this->assertSame(10, $this->onHand($plenty));
        $this->assertSame(1, $this->onHand($scarce));
        $this->assertSame(0, Invoice::where('company_id', $this->company->id)->count());
        $this->assertSame(0, StockMovement::where('reference_type', 'invoice')->count());
    }

    public function test_on_hand_quantity_can_never_be_driven_negative(): void
    {
        $item = $this->stockedItem(2);

        $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch', 'stock_item_id' => $item->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 2, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers())->assertCreated();
        $this->assertSame(0, $this->onHand($item));

        // A second invoice against an emptied item is refused.
        $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch', 'stock_item_id' => $item->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers())->assertStatus(422);
        $this->assertSame(0, $this->onHand($item));
    }

    public function test_stock_at_another_warehouse_cannot_satisfy_this_one(): void
    {
        $item = $this->stockedItem(10);
        $otherWarehouse = Warehouse::factory()->for($this->company)->create();

        $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch', 'stock_item_id' => $item->id,
                'warehouse_id' => $otherWarehouse->id, 'quantity' => 1, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers())->assertStatus(422);

        $this->assertSame(10, $this->onHand($item));
    }

    public function test_a_stock_line_must_say_which_warehouse_to_issue_from(): void
    {
        $item = $this->stockedItem(10);

        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch', 'stock_item_id' => $item->id,
                'quantity' => 1, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertStringContainsString('warehouse', $response->json('detail'));
        $this->assertSame(10, $this->onHand($item));
    }

    public function test_the_cost_recorded_is_the_average_at_issue_not_a_later_one(): void
    {
        $item = $this->stockedItem(10, '10.0000');

        $invoice = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Switch', 'stock_item_id' => $item->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 2, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers())->assertCreated();

        // A later receipt at a higher price re-weights the item...
        InventoryService::receiveStock(
            $this->company->id, $item->id, $this->warehouse->id, 8, '20.0000',
            referenceType: 'test', referenceId: (string) Str::orderedUuid(),
        );
        $this->assertGreaterThan(10, (float) $item->refresh()->avg_cost);

        // ...but the invoice's own cost does not move with it, so its
        // gross profit stays what it was on the day.
        $reread = $this->getJson("/api/invoices/{$invoice->json('id')}", $this->headers())->assertOk();
        $this->assertEqualsWithDelta(20, $reread->json('cost_sgd'), 0.001);
        $this->assertEqualsWithDelta(10, $reread->json('lines.0.unit_cost_sgd'), 0.001);
    }

    // ── Existing invoices are untouched ─────────────────────────────

    public function test_an_auto_issued_invoice_still_has_no_lines(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-LEGACY-1', 'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'Annual support', 'amount_sgd' => '1000.00', 'tax_code' => 'SR',
            'gst_rate' => '9.00', 'gst_amount_sgd' => '90.00', 'total_amount_sgd' => '1090.00',
        ]);
        $invoice->forceFill(['issued_at' => now()])->save();

        $response = $this->getJson("/api/invoices/{$invoice->id}", $this->headers());

        // Lines are optional: a header-only invoice reads back exactly
        // as it always did, with an empty list rather than an error.
        $response->assertOk()->assertJsonCount(0, 'lines');
        $this->assertEqualsWithDelta(1090, $response->json('total_amount_sgd'), 0.001);
    }

    // ── Access and scoping ──────────────────────────────────────────

    public function test_issuing_is_audited(): void
    {
        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price_sgd' => 500]],
        ], $this->headers())->assertCreated();

        $entry = AuditLogEntry::where('entity_type', 'invoice')
            ->where('entity_id', $response->json('id'))->where('action', 'issued')->firstOrFail();
        $this->assertStringContainsString('invoice_type=sales', (string) $entry->details);
    }

    public function test_another_companys_stock_item_is_not_found(): void
    {
        $otherCompany = Company::factory()->create();
        $theirWarehouse = Warehouse::factory()->for($otherCompany)->create();
        $theirItem = StockItem::factory()->for($otherCompany)->create();
        InventoryService::receiveStock(
            $otherCompany->id, $theirItem->id, $theirWarehouse->id, 50, '10.0000',
            referenceType: 'test', referenceId: (string) Str::orderedUuid(),
        );

        $response = $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'description' => 'Their stock', 'stock_item_id' => $theirItem->id,
                'warehouse_id' => $theirWarehouse->id, 'quantity' => 1, 'unit_price_sgd' => 25,
            ]],
        ], $this->headers());

        // A valid uuid from another company would satisfy the foreign
        // key; it must not satisfy the endpoint.
        $response->assertStatus(404);
        $this->assertSame(50, (int) StockLevel::where('stock_item_id', $theirItem->id)->value('quantity'));
    }

    public function test_an_invoice_needs_at_least_one_line(): void
    {
        $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id, 'lines' => [],
        ], $this->headers())->assertStatus(422);
    }

    public function test_a_user_with_no_group_cannot_raise_one(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        $this->postJson('/api/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price_sgd' => 1]],
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }
}
