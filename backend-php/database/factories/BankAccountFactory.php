<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'bank_name' => 'DBS Bank',
            'account_name' => 'Webmaster Consultancy Pte Ltd',
            'account_number' => (string) fake()->numerify('###-######-#'),
            'currency_code' => 'SGD',
        ];
    }
}
