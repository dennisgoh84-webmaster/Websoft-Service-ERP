<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\SupplierInvoice;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    public function definition(): array
    {
        $supplier = CompanyIndividual::factory()->create(['is_supplier' => true]);

        return [
            'company_id' => $supplier->company_id,
            'supplier_id' => $supplier->id,
            'bill_number' => Numbering::next($supplier->company_id, 'supplier_invoice'),
            'invoice_date' => now()->toDateString(),
            'description' => 'Office supplies',
            'amount_sgd' => 1000,
            'gst_amount_sgd' => 90,
            'total_amount_sgd' => 1090,
        ];
    }
}
