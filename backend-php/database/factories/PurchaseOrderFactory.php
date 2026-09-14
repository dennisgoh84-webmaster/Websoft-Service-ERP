<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\PurchaseOrder;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        $supplier = CompanyIndividual::factory()->create(['is_supplier' => true]);

        return [
            'company_id' => $supplier->company_id,
            'supplier_id' => $supplier->id,
            'po_number' => Numbering::next($supplier->company_id, 'purchase_order'),
            'order_date' => now()->toDateString(),
            'description' => 'Office supplies',
            'amount_sgd' => 1000,
            'gst_amount_sgd' => 90,
            'total_amount_sgd' => 1090,
        ];
    }
}
