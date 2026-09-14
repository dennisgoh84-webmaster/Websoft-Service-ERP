<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'country' => 'Singapore',
            'currency' => 'SGD',
            'timezone' => 'Asia/Singapore',
            'is_active' => true,
        ];
    }
}
