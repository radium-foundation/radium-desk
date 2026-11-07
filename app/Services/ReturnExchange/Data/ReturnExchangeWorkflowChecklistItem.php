<?php

namespace App\Services\ReturnExchange\Data;

final class ReturnExchangeWorkflowChecklistItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly ?string $detail = null,
    ) {}
}
