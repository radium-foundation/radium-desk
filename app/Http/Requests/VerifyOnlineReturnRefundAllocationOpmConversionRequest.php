<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocationOpmConversion;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOnlineReturnRefundAllocationOpmConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversion = $this->route('conversion');

        return $conversion instanceof OnlineReturnRefundAllocationOpmConversion
            && $this->user() !== null
            && $this->user()->can('verifyAndFinalize', $conversion);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
