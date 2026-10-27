<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewPosReturnRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('pos_return_refund_request');

        return $request !== null
            && $this->user() !== null
            && $this->user()->can('review', $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'review_reason' => [
                Rule::requiredIf(fn (): bool => $this->input('decision') === 'reject'),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
