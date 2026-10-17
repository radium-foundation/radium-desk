<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocationLine;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteOnlineReturnRefundAllocationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $line = $this->route('line');

        return $line instanceof OnlineReturnRefundAllocationLine
            && $this->user() !== null
            && $this->user()->can('execute', $line);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'execution_reference' => ['nullable', 'string', 'max:128'],
            'transaction_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
