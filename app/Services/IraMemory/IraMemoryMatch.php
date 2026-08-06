<?php

namespace App\Services\IraMemory;

use App\Models\IraMemory;

final readonly class IraMemoryMatch
{
    public function __construct(
        public IraMemory $memory,
        public string $matchedOn,
        public string $matchedValue,
    ) {}
}
