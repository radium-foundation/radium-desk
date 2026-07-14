<?php

namespace App\Services\Refunds;

use App\Contracts\Refunds\RefundExecutor;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ManualRefundExecutor implements RefundExecutor
{
    public function supports(ApprovedRefundMethod $method): bool
    {
        return true;
    }

    public function execute(RefundRequest $refund, User $actor, array $payload): array
    {
        $reference = trim((string) ($payload['reference_number'] ?? ''));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        $remarks = trim((string) ($payload['remarks'] ?? ''));

        if ($reference === '' && $transactionId === '') {
            throw ValidationException::withMessages([
                'execution_reference_no' => 'Enter a reference number or transaction ID to complete the refund.',
            ]);
        }

        return [
            'provider' => 'manual',
            'reference_number' => $reference !== '' ? $reference : null,
            'transaction_id' => $transactionId !== '' ? $transactionId : null,
            'remarks' => $remarks !== '' ? $remarks : null,
            'metadata' => [
                'method' => $refund->approved_refund_method?->value,
                'executed_by' => $actor->id,
            ],
        ];
    }
}
