<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesRefundRequestPayload;
use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequestRequest extends FormRequest
{
    use ValidatesRefundRequestPayload;

    public function authorize(): bool
    {
        return $this->user()?->can('refunds.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::refundRequestValidationRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::refundRequestValidationAttributes();
    }

    protected function prepareForValidation(): void
    {
        $this->mergeRefundRequestDefaults();
    }
}
