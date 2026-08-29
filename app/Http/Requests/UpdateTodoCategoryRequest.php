<?php

namespace App\Http\Requests;

use App\Models\TodoCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTodoCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TodoCategory $todoCategory */
        $todoCategory = $this->route('todoCategory');

        return $this->user()?->can('update', $todoCategory) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var TodoCategory $todoCategory */
        $todoCategory = $this->route('todoCategory');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('todo_categories', 'name')->ignore($todoCategory->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A to-do category with this name already exists. Activate the existing category instead of creating a duplicate.',
        ];
    }
}
