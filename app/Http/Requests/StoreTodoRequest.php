<?php

namespace App\Http\Requests;

use App\Enums\TodoPriority;
use App\Models\Todo;
use App\Support\Todos\TodoPanelRenderer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Todo::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', Rule::in(TodoPriority::values())],
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
     * @return array{title: string, description: ?string, priority: ?string, assigned_to: ?int, due_at: ?string, remind_at: ?string}
     */
    public function todoData(): array
    {
        $validated = $this->validated();

        return [
            'title' => (string) $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? TodoPriority::Normal->value,
            'assigned_to' => isset($validated['assigned_to']) ? (int) $validated['assigned_to'] : $this->user()?->id,
            'due_at' => $validated['due_at'] ?? null,
            'remind_at' => $validated['remind_at'] ?? null,
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if (TodoPanelRenderer::wantsPanel($this)) {
            throw new HttpResponseException(
                app(TodoPanelRenderer::class)->createFormWithErrors($this, $validator),
            );
        }

        parent::failedValidation($validator);
    }
}
