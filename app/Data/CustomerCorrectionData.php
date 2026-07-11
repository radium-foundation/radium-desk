<?php

namespace App\Data;

readonly class CustomerCorrectionData
{
    public function __construct(
        public ?string $customerName,
        public ?string $customerPhone,
        public ?string $customerEmail,
        public ?string $reason,
    ) {}
}
