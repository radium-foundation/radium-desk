<?php

namespace App\Support\Repair\Data;

readonly class RepairClassification
{
    public function __construct(
        public string $action,
        public string $category,
        public ?string $skipReason = null,
        public int $priority = 100,
    ) {}

    public function shouldSkip(): bool
    {
        return $this->action === 'skip' || $this->skipReason !== null;
    }
}
