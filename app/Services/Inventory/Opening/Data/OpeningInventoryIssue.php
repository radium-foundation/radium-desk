<?php

namespace App\Services\Inventory\Opening\Data;

final class OpeningInventoryIssue
{
    public function __construct(
        public readonly string $sheet,
        public readonly int $rowNumber,
        public readonly string $code,
        public readonly string $message,
        public readonly bool $blocking = true,
    ) {}
}
