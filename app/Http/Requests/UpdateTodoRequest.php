<?php

namespace App\Http\Requests;

use App\Enums\TodoPriority;
use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Todo $todo */
        $todo = $this->route('todo');

        return $this->user()?->can('update', $todo) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(TodoPriority::values())],
            'assigned_to' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'due_at' => ['nullable', 'date'],
            'remind_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{title: string, description: ?string, priority: string, assigned_to?: int, due_at: ?string, remind_at: ?string}
     */
    public function todoData(): array
    {
        $validated = $this->validated();

        $data = [
            'title' => (string) $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => (string) $validated['priority'],
            'due_at' => $validated['due_at'] ?? null,
            'remind_at' => $validated['remind_at'] ?? null,
        ];

        if (array_key_exists('assigned_to', $validated) && $validated['assigned_to'] !== null) {
            $data['assigned_to'] = (int) $validated['assigned_to'];
        }

        return $data;
    }
}
