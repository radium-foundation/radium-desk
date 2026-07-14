<?php

namespace App\Services\Refunds;

use App\Contracts\Refunds\RefundExecutor;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class RefundExecutorResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function for(ApprovedRefundMethod $method): RefundExecutor
    {
        /** @var RefundExecutor $executor */
        $executor = $this->container->make(ManualRefundExecutor::class);

        if (! $executor->supports($method)) {
            throw new InvalidArgumentException("No refund executor supports [{$method->value}].");
        }

        return $executor;
    }

    /**
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
    public function execute(RefundRequest $refund, User $actor, array $payload): array
    {
        $method = $refund->approved_refund_method;

        if (! $method instanceof ApprovedRefundMethod) {
            throw new InvalidArgumentException('Approved refund method is required before execution.');
        }

        return $this->for($method)->execute($refund, $actor, $payload);
    }
}
