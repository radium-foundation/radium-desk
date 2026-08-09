<?php

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'remindable_type' => Todo::class,
            'remindable_id' => Todo::factory(),
            'user_id' => User::factory(),
            'remind_at' => now()->addHour(),
            'status' => ReminderStatus::Pending,
            'dispatched_at' => null,
            'notification_id' => null,
            'idempotency_key' => 'todo-reminder.'.Str::uuid()->toString(),
            'metadata' => null,
        ];
    }

    public function forTodo(Todo $todo): static
    {
        return $this->state(fn (array $attributes) => [
            'remindable_type' => Todo::class,
            'remindable_id' => $todo->id,
            'user_id' => $todo->assigned_to,
        ]);
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReminderStatus::Dispatched,
            'dispatched_at' => now(),
            'notification_id' => (string) Str::uuid(),
        ]);
    }
}
