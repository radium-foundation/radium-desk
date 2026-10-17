<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocationOpmConversion;
use Illuminate\Foundation\Http\FormRequest;

class RecordOnlineReturnRefundAllocationOpmPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversion = $this->route('conversion');

        return $conversion instanceof OnlineReturnRefundAllocationOpmConversion
            && $this->user() !== null
            && $this->user()->can('recordPayout', $conversion);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cashfree_order_id' => ['required', 'string', 'max:64'],
            'cashfree_refund_id' => ['required', 'string', 'max:128'],
            'cashfree_refund_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }
}
