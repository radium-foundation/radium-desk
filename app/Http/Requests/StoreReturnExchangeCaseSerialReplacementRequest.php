<?php

namespace App\Http\Requests;

use App\Models\ReturnExchangeCase;
use Illuminate\Foundation\Http\FormRequest;

class StoreReturnExchangeCaseSerialReplacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');

        return $case instanceof ReturnExchangeCase
            && $this->user() !== null
            && $this->user()->can('performSerialReplacement', $case);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'return_exchange_case_receipt_id' => ['required', 'integer'],
            'replacement_serial_number' => ['required', 'string', 'max:128'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
