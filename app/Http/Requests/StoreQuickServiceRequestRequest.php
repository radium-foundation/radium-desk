<?php

namespace App\Http\Requests;

use App\Enums\IncidentSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuickServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('orders.view')
            && $user->can('incidents.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'string', 'max:50'],
            'serial_number' => ['required', 'string', 'max:100'],
            'product' => ['required', 'string', Rule::in(config('products'))],
            'source' => ['required', Rule::enum(IncidentSource::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'high_priority' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_id' => 'order ID',
            'serial_number' => 'serial number',
            'product' => 'product',
            'source' => 'source',
            'notes' => 'comment',
            'high_priority' => 'high priority',
        ];
    }
}
