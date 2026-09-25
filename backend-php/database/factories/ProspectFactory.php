<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\Prospect;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    public function definition(): array
    {
        return [
            'customer_id' => CompanyIndividual::factory(),
            'company_id' => fn (array $a) => CompanyIndividual::find($a['customer_id'])->company_id,
            'prospect_number' => fn (array $a) => Numbering::next($a['company_id'], 'prospect'),
            'title' => fake()->sentence(3),
            'status' => Prospect::STATUS_OPEN,
        ];
    }
}
