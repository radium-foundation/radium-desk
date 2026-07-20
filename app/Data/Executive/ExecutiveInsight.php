<?php

namespace App\Data\Executive;

use App\Enums\PlatformHealthStatus;

readonly class ExecutiveInsight
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $metricId,
        public string $code,
        public string $message,
        public PlatformHealthStatus $severity,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'metric_id' => $this->metricId,
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'meta' => $this->meta,
        ];
    }
}
