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
            'reject_reason' => ['nullable', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'review_notes' => 'reject reason',
            'reject_reason' => 'reject reason',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('reject_reason') && ! $this->filled('review_notes')) {
            $this->merge([
                'review_notes' => $this->string('reject_reason')->toString(),
            ]);
        }
    }
}
