<?php

namespace Database\Factories;

use App\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('ITM-####')),
            'name' => fake()->words(3, true),
            'unit_of_measure' => 'PCS',
            'reorder_level' => 0,
            'is_active' => true,
        ];
    }
}
