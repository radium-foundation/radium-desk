<?php

namespace App\Http\Requests;

use App\Models\ReturnExchangeCase;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPosReturnRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');

        return $case instanceof ReturnExchangeCase
            && $this->user() !== null
            && $this->user()->can('submitPosRefund', $case);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requester_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
