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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('reference_no')) {
                $validator->errors()->add(
                    'reference_no',
                    'Refund reference is assigned automatically when the request is submitted.',
                );
            }
        });
    }
}
