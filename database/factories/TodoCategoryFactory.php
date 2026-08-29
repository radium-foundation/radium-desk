<?php

namespace Database\Factories;

use App\Models\TodoCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TodoCategory>
 */
class TodoCategoryFactory extends Factory
{
    protected $model = TodoCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
