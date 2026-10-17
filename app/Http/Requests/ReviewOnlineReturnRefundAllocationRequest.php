<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewOnlineReturnRefundAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $allocation = $this->route('online_return_refund_allocation');

        return $allocation instanceof OnlineReturnRefundAllocation
            && $this->user() !== null
            && $this->user()->can('review', $allocation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'review_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
