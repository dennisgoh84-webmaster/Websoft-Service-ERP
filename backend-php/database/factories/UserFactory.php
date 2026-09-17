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
        $email = fake()->unique()->safeEmail();
        // Generate username from email part (before @)
        $username = strtolower(explode('@', $email)[0]);

        return [
            'company_id' => Company::factory(),
            'username' => $username,
            'email' => $email,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
            'full_name' => fake()->name(),
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'must_change_password' => false,
            'force_password_change_on_login' => false,
            'is_active' => true,
        ];
    }
}
