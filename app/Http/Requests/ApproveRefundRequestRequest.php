<?php

namespace App\Http\Requests;

use App\Models\RefundRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');

        return $refund instanceof RefundRequest
            && ($this->user()?->can('refunds.review') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'refund_transaction_id' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'review_notes' => 'review notes',
            'refund_transaction_id' => 'refund transaction ID',
        ];
    }
}
