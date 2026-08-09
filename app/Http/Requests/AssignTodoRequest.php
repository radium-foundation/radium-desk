<?php

namespace App\Http\Requests;

use App\Models\Todo;
use App\Support\Todos\TodoPanelRenderer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AssignTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Todo $todo */
        $todo = $this->route('todo');

        return $this->user()?->can('assign', $todo) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if (TodoPanelRenderer::wantsPanel($this)) {
            /** @var Todo $todo */
            $todo = $this->route('todo');

            throw new HttpResponseException(
                app(TodoPanelRenderer::class)->detailWithErrors($this, $todo, $validator),
            );
        }

        parent::failedValidation($validator);
    }
}
