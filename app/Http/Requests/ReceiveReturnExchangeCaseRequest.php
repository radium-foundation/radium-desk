<?php

namespace App\Http\Requests;

use App\Models\ReturnExchangeCase;
use Illuminate\Foundation\Http\FormRequest;

class ReceiveReturnExchangeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');

        return $case instanceof ReturnExchangeCase
            && $this->user()?->can('receive', $case);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'return_exchange_case_line_id' => ['required', 'integer', 'min:1'],
            'serial_number_scanned' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'qty_received' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ];
    }
}
