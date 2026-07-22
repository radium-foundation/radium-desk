<?php

namespace App\Support\Repair\Data;

use App\Support\Repair\Enums\RepairPhase;

readonly class RepairBatchOptions
{
    /**
     * @param  array<string, mixed>  $extras
     */
    public function __construct(
        public RepairPhase $phase,
        public bool $dryRun = true,
        public bool $execute = false,
        public bool $force = false,
        public bool $quiet = false,
        public bool $json = false,
        public bool $csv = false,
        public bool $resume = false,
        public ?string $batchUuid = null,
        public ?int $limit = null,
        public int $offset = 0,
        public ?string $since = null,
        public ?string $until = null,
        public ?string $exportPath = null,
        public int $checkpointEvery = 10,
        public bool $notify = false,
        public array $extras = [],
    ) {}

    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }
}
