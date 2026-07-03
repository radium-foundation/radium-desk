<?php

namespace App\Data\RadiumBox;

readonly class RadiumBoxManualSyncResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public float $durationMs,
        public bool $serialApplied = false,
    ) {}
}
