<?php

namespace App\Http\Requests;

use App\Models\OnlineReturnRefundAllocationOpmConversion;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseOnlineReturnRefundAllocationOpmConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversion = $this->route('conversion');

        return $conversion instanceof OnlineReturnRefundAllocationOpmConversion
            && $this->user() !== null
            && $this->user()->can('release', $conversion);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
