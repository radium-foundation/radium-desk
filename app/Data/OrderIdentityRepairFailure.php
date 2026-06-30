<?php

namespace App\Data;

use App\Enums\OrderIdentityRepairFailureCategory;

readonly class OrderIdentityRepairFailure
{
    public function __construct(
        public string $orderId,
        public string $message,
        public OrderIdentityRepairFailureCategory $category,
    ) {}

    public function displayReason(): string
    {
        return sprintf('%s: %s', $this->category->label(), $this->message);
    }
}
