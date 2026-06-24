<?php

namespace App\Http\Requests;

use App\Models\RefundRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectRefundRequestRequest extends FormRequest
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
            'review_notes' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'review_notes' => 'review notes',
        ];
    }
}
