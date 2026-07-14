<?php

namespace App\Http\Requests;

use App\Models\RefundRequest;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');

        return $refund instanceof RefundRequest
            && ($this->user()?->can('refunds.execute') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'execution_reference_no' => ['nullable', 'string', 'max:100'],
            'execution_transaction_id' => ['nullable', 'string', 'max:100'],
            'execution_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'execution_reference_no' => 'reference number',
            'execution_transaction_id' => 'transaction ID',
            'execution_remarks' => 'execution notes',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $reference = trim((string) $this->input('execution_reference_no', ''));
            $transactionId = trim((string) $this->input('execution_transaction_id', ''));

            if ($reference === '' && $transactionId === '') {
                $validator->errors()->add(
                    'execution_reference_no',
                    'Enter a reference number or transaction ID.',
                );
            }
        });
    }
}
