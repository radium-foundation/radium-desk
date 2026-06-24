<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('refunds.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'incident_id' => [
                'nullable',
                'integer',
                Rule::exists('incidents', 'id')
                    ->where('order_id', $this->integer('order_id'))
                    ->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_id' => 'order',
            'incident_id' => 'incident',
            'amount' => 'amount',
            'reason' => 'reason',
        ];
    }
}
