<?php

namespace App\Data;

readonly class OrderCorrectionChange
{
    public function __construct(
        public string $label,
        public string $previous,
        public string $next,
    ) {}
}
