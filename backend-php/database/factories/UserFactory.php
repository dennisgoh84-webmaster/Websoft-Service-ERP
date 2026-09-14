<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => fake()->unique()->safeEmail(),
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'full_name' => fake()->name(),
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'must_change_password' => false,
            'is_active' => true,
        ];
    }
}
