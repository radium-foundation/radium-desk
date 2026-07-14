<?php

namespace App\Http\Requests;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundDifferenceReason;
use App\Models\RefundRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'approved_refund_method' => ['required', Rule::enum(ApprovedRefundMethod::class)],
            'deduction_profile_key' => ['nullable', Rule::enum(RefundDeductionProfile::class)],
            'cancellation_charges' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'gst_on_cancellation' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'other_deduction' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'refund_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'partial_difference_reason' => ['nullable', Rule::enum(RefundDifferenceReason::class)],
            'partial_difference_notes' => ['nullable', 'string', 'max:2000'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'approved_refund_method' => 'refund method',
            'refund_amount' => 'refund amount',
            'review_notes' => 'review notes',
            'partial_difference_reason' => 'reason for difference',
        ];
    }
}
