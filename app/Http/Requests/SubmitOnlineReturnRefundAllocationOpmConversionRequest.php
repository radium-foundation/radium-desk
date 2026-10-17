<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocationLine;
use Illuminate\Foundation\Http\FormRequest;

class SubmitOnlineReturnRefundAllocationOpmConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $line = $this->route('line');

        return $line instanceof OnlineReturnRefundAllocationLine
            && $this->user() !== null
            && $this->user()->can('requestOpmConversion', $line);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
