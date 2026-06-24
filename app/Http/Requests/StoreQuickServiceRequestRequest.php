<?php

namespace App\Http\Requests;

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
            'customer_id' => ['required', 'string', 'max:100'],
            'serial_number' => ['required', 'string', 'max:100'],
            'product' => ['required', 'string', Rule::in(config('products'))],
            'notes' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'customer ID',
            'serial_number' => 'serial number',
            'product' => 'product',
            'notes' => 'notes',
        ];
    }
}
