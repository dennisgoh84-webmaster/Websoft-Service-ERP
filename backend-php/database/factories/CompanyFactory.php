<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    // The subset of DatabaseSeeder::CHART_OF_ACCOUNTS that
    // App\Services\Posting's ACCOUNT MAP actually posts to. Every
    // BillingService/PayablesService test creates a Company via this
    // factory, and issuing an invoice or auto-approving a bill now
    // posts to the GL as an integral step (not an optional one) --
    // see those classes' docblocks -- so a factory-made Company needs
    // a minimal chart to exist, the same as a real company would set
    // one up before using Billing. Kept to only the accounts Posting
    // references (not the full 35-line demo chart) so this stays fast
    // across the whole suite.
    private const MINIMAL_CHART_OF_ACCOUNTS = [
        ['1000', 'Cash at bank', Account::TYPE_ASSET],
        ['1100', 'Accounts receivable', Account::TYPE_ASSET],
        ['2000', 'Accounts payable', Account::TYPE_LIABILITY],
        ['2100', 'GST output tax (collected on sales)', Account::TYPE_LIABILITY],
        ['2110', 'GST input tax (paid on purchases)', Account::TYPE_LIABILITY],
        ['4000', 'Service contract revenue', Account::TYPE_REVENUE],
        ['4010', 'Excess usage revenue', Account::TYPE_REVENUE],
        ['4030', 'Hardware sales', Account::TYPE_REVENUE],
        ['5000', 'Cost of services', Account::TYPE_EXPENSE],
        ['6700', 'Bad debts written off', Account::TYPE_EXPENSE],
        ['6800', 'Exchange (gain) / loss', Account::TYPE_EXPENSE],
    ];

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

    public function configure(): static
    {
        return $this->afterCreating(function (Company $company) {
            $now = now();
            Account::insert(array_map(fn ($row) => [
                'id' => (string) Str::orderedUuid(),
                'company_id' => $company->id,
                'code' => $row[0],
                'name' => $row[1],
                'account_type' => $row[2],
                'is_active' => true,
                'created_at' => $now,
            ], self::MINIMAL_CHART_OF_ACCOUNTS));
        });
    }
}
