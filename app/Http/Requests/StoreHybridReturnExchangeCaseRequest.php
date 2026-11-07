<?php

namespace App\Http\Requests;

use App\Enums\ReturnExchangeReplacementCategory;
use App\Models\ReturnExchangeCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHybridReturnExchangeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ReturnExchangeCase::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inventory_sale_id' => ['required', 'integer', 'min:1', 'exists:inventory_sales,id'],
            'replacement_category' => ['required', Rule::enum(ReturnExchangeReplacementCategory::class)],
            'reason' => ['required', 'string', 'max:2000'],
            'customer_request_summary' => ['nullable', 'string', 'max:5000'],
            'requested_connector_change' => ['nullable', 'string', 'max:500'],
            'hybrid_workflow_notes' => ['nullable', 'string', 'max:5000'],
            'requester_remarks' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:1', 'max:1'],
            'lines.0.description' => ['required', 'string', 'max:500'],
            'lines.0.serial_number' => ['required', 'string', 'max:128'],
            'lines.0.qty' => ['nullable', 'integer', 'min:1', 'max:1'],
        ];
    }
}
