<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->sentence(),
        ];
    }
}
