<?php

namespace App\Http\Requests;

use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Models\ReturnExchangeCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitOnlineReturnRefundAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');

        return $case instanceof ReturnExchangeCase
            && $this->user() !== null
            && $this->user()->can('submitOnlineRefundAllocation', $case);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.destination' => ['required', 'string', Rule::in(array_map(
                fn (OnlineReturnRefundAllocationDestination $d): string => $d->value,
                OnlineReturnRefundAllocationDestination::supported(),
            ))],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.execution_reference' => ['nullable', 'string', 'max:128'],
            'requester_remarks' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
