<?php

namespace App\Http\Requests;

use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Services\Refunds\RefundExecutionInputGuard;
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

    protected function prepareForValidation(): void
    {
        if (! $this->isWalletApproved()) {
            return;
        }

        $this->merge([
            'execution_reference_no' => null,
            'execution_transaction_id' => null,
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $refund = $this->route('refund');
            if (! $refund instanceof RefundRequest) {
                return;
            }

            if ($this->isWalletApproved()) {
                return;
            }

            $reference = trim((string) $this->input('execution_reference_no', ''));
            $transactionId = trim((string) $this->input('execution_transaction_id', ''));

            if ($reference === '' && $transactionId === '') {
                $validator->errors()->add(
                    'execution_reference_no',
                    'Enter an external payout reference or transaction ID.',
                );

                return;
            }

            if (RefundExecutionInputGuard::matchesDeskRefundReference($refund, $reference)) {
                $validator->errors()->add(
                    'execution_reference_no',
                    'The Desk refund reference is assigned automatically. Enter the external payout reference (UTR, gateway ID, etc.) instead.',
                );
            }

            if (RefundExecutionInputGuard::matchesDeskRefundReference($refund, $transactionId)) {
                $validator->errors()->add(
                    'execution_transaction_id',
                    'The Desk refund reference cannot be used as a transaction ID.',
                );
            }
        });
    }

    private function isWalletApproved(): bool
    {
        $refund = $this->route('refund');

        return $refund instanceof RefundRequest
            && $refund->approved_refund_method === ApprovedRefundMethod::Wallet;
    }
}
