<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Document lines come back in the order they were keyed in
 * (App\Models\Concerns\HasLineNumber). These line tables have UUID
 * keys, and ordering by id had returned them shuffled -- found by the
 * self-test (docs/self-test.md) when a phone run read a quotation's
 * second line back as its first. Enough lines here that a random order
 * would all but never pass by luck.
 */
class DocumentLineOrderTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Company $company): array
    {
        $owner = User::factory()->for($company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $token = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_quotation_lines_keep_the_order_keyed(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $headers = $this->headers($company);
        $descriptions = array_map(fn ($i) => "Line {$i}", range(1, 12));

        $id = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => '2026-09-26',
            'lines' => array_map(fn ($d) => ['description' => $d, 'quantity' => 1, 'unit_price_sgd' => 10], $descriptions),
        ], $headers)->assertOk()->json('id');

        $read = $this->getJson("/api/quotations/{$id}", $headers)->assertOk();
        $this->assertSame($descriptions, array_column($read->json('lines'), 'description'));
    }

    public function test_goods_receive_note_lines_keep_the_order_keyed(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $items = StockItem::factory()->for($company)->count(10)->create();
        $headers = $this->headers($company);

        $id = $this->postJson('/api/stock/grn', [
            'warehouse_id' => $warehouse->id,
            'lines' => $items->map(fn ($item, $i) => ['stock_item_id' => $item->id, 'quantity' => $i + 1, 'unit_cost' => 5])->all(),
        ], $headers)->assertStatus(201)->json('id');

        $read = $this->getJson("/api/stock/grn/{$id}", $headers)->assertOk();
        $this->assertSame(range(1, 10), array_column($read->json('lines'), 'quantity'));
    }
}
