<?php

namespace Database\Factories;

use App\Enums\FinancePartyKind;
use App\Models\FinanceParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceParty>
 */
class FinancePartyFactory extends Factory
{
    protected $model = FinanceParty::class;

    public function definition(): array
    {
        return [
            'code' => 'PTY-TMP-'.fake()->unique()->numerify('########'),
            'legal_name' => fake()->company(),
            'trade_name' => fake()->optional()->company(),
            'kind' => FinancePartyKind::Organisation,
            'phone' => '98'.fake()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (FinanceParty $party): void {
            $party->update(['code' => sprintf('PTY-%06d', $party->id)]);
        });
    }
}
