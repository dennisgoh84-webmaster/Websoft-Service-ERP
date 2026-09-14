<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        $customer = CompanyIndividual::factory()->create();
        $start = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'contract_number' => Numbering::next($customer->company_id, 'contract'),
            'status' => Contract::STATUS_DRAFT,
            'contract_kind' => Contract::KIND_SERVICE_SUPPORT,
            'contracted_minutes' => 600, // 10 hours
            'consumed_minutes' => 0,
            'contract_value_sgd' => 3000,
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+12 months'),
        ];
    }
}
