<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\Quotation;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        $customer = CompanyIndividual::factory()->create();

        return [
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'quotation_number' => Numbering::next($customer->company_id, 'quotation'),
            'quotation_date' => now()->toDateString(),
            'status' => Quotation::STATUS_DRAFT,
        ];
    }
}
