<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => 'RD-FACTORY-'.fake()->unique()->numerify('########'),
            'serial_number' => (string) fake()->numberBetween(1000000, 9999999),
            'product_name' => fake()->words(2, true),
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => User::factory(),
        ];
    }
}
