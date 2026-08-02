<?php

namespace App\Data\Platform;

use App\Enums\PlatformAlertSeverity;
use Illuminate\Support\Carbon;

readonly class PlatformAlert
{
    public function __construct(
        public string $id,
        public string $source,
        public string $groupKey,
        public string $title,
        public string $summary,
        public PlatformAlertSeverity $severity,
        public string $status,
        public ?Carbon $lastUpdated = null,
        public int $count = 1,
        public ?string $link = null,
        /** @var list<array<string, mixed>> */
        public array $related = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'group_key' => $this->groupKey,
            'title' => $this->title,
            'summary' => $this->summary,
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'status' => $this->status,
            'last_updated' => $this->lastUpdated?->toIso8601String(),
            'count' => $this->count,
            'link' => $this->link,
            'related' => $this->related,
        ];
    }
}
