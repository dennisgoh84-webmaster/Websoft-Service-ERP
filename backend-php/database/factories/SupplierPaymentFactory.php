<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\SupplierPayment;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    protected $model = SupplierPayment::class;

    public function definition(): array
    {
        $supplier = CompanyIndividual::factory()->create(['is_supplier' => true]);

        return [
            'company_id' => $supplier->company_id,
            'supplier_id' => $supplier->id,
            'voucher_number' => Numbering::next($supplier->company_id, 'payment'),
            'payment_date' => now()->toDateString(),
            'amount_sgd' => 200,
        ];
    }
}
