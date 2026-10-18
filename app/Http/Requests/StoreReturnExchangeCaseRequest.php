<?php

namespace App\Http\Requests;

use App\Enums\ReturnExchangeCaseSourceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnExchangeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\ReturnExchangeCase::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_kind' => ['required', Rule::enum(ReturnExchangeCaseSourceKind::class)],
            'inventory_sale_id' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'hardware_fulfilment_id' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'reason' => ['required', 'string', 'max:2000'],
            'requester_remarks' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.qty' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:128'],
            'lines.*.inventory_product_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.inventory_sale_line_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
