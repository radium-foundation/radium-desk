<?php

namespace App\Contracts\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;

interface RefundExecutor
{
    public function supports(ApprovedRefundMethod $method): bool;

    /**
     * Record or initiate payout for an approved refund.
     *
     * @param  array{
     *     reference_number?: string|null,
     *     transaction_id?: string|null,
     *     remarks?: string|null,
     * }  $payload
     * @return array{
     *     provider: string,
     *     reference_number: string|null,
     *     transaction_id: string|null,
     *     remarks: string|null,
     *     metadata: array<string, mixed>,
     * }
     */
    public function execute(RefundRequest $refund, User $actor, array $payload): array;
}
