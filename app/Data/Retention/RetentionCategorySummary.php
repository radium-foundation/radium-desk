<?php

namespace App\Data\Retention;

readonly class RetentionCategorySummary
{
    public function __construct(
        public string $key,
        public string $table,
        public string $label,
        public int $retentionDays,
        public ?string $cutoffAt,
        public int $candidateCount,
        public int $tableTotalCount,
    ) {}
}
