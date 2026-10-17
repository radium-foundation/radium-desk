<?php

namespace App\Http\Requests;

use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseReceipt;
use Illuminate\Foundation\Http\FormRequest;

class InspectReturnExchangeCaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');
        $receipt = $this->route('receipt');

        return $case instanceof ReturnExchangeCase
            && $receipt instanceof ReturnExchangeCaseReceipt
            && $this->user()?->can('inspect', $case);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_match_passed' => ['required', 'boolean'],
            'device_operates_ok' => ['required', 'boolean'],
            'accessories_verified' => ['required', 'boolean'],
            'repair_hold' => ['nullable', 'boolean'],
            'mark_damaged' => ['nullable', 'boolean'],
            'condition_code' => ['nullable', 'string', 'max:40'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
