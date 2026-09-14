<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyIndividual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyIndividual>
 */
class CompanyIndividualFactory extends Factory
{
    protected $model = CompanyIndividual::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'customer_type' => CompanyIndividual::TYPE_COMPANY,
            'name' => fake()->unique()->company(),
            'billing_email' => fake()->unique()->companyEmail(),
        ];
    }
}
