<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_type' => Product::TYPE_SERVICE,
            'name' => fake()->unique()->words(3, true),
            'sales_price_sgd' => fake()->randomFloat(2, 50, 5000),
            'tax_code' => 'SR',
        ];
    }
}
