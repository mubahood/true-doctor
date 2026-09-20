<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StockItem> */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'hospital_id' => Hospital::factory(),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'unit' => 'tablets',
            'current_quantity' => '0.00',
            'cost_price' => '2.00',
            'sale_price' => '3.00',
            'current_stock_value' => '0.00',
            'reorder_level' => '10.00',
            'is_active' => true,
        ];
    }
}
