<?php

namespace App\Http\Requests;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('incidents.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'category' => ['required', 'string', 'max:100'],
            'source' => ['required', Rule::enum(IncidentSource::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'status' => ['required', Rule::enum(IncidentStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_id' => 'order',
            'category' => 'category',
            'source' => 'source',
            'title' => 'title',
            'description' => 'description',
            'status' => 'status',
        ];
    }
}
