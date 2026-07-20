<?php

namespace App\Data\Platform;

use App\Enums\PlatformCardSize;
use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Carbon;

readonly class PlatformCardPayload
{
    /**
     * @param  list<PlatformMetric>  $metrics
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $section,
        public PlatformHealthStatus $status,
        public Carbon $generatedAt,
        public array $metrics = [],
        public array $meta = [],
        public ?string $detailUrl = null,
        public array $actions = [],
        public ?string $bodyPartial = null,
        public PlatformCardSize $size = PlatformCardSize::Large,
        public bool $refreshable = true,
        public bool $pinned = false,
        public int $sortKey = 0,
        public ?string $subtitle = null,
        public ?string $icon = null,
    ) {}

    public static function fromDefinition(
        PlatformCardDefinition $definition,
        PlatformHealthStatus $status,
        Carbon $generatedAt,
        array $metrics = [],
        array $meta = [],
        ?string $detailUrl = null,
    ): self {
        return new self(
            key: $definition->id,
            title: $definition->title,
            section: $definition->section,
            status: $status,
            generatedAt: $generatedAt,
            metrics: $metrics,
            meta: $meta,
            detailUrl: $detailUrl ?? $definition->detailUrl,
            actions: $definition->actions,
            bodyPartial: $definition->bodyPartial,
            size: $definition->size,
            refreshable: $definition->refreshable,
            pinned: $definition->pinned,
            sortKey: $definition->sortKey(),
            subtitle: $definition->subtitle,
            icon: $definition->icon,
        );
    }

    public function statusLabel(): string
    {
        return $this->status->label();
    }

    public function badgeClass(): string
    {
        return $this->status->badgeClass();
    }

    public function columnClass(): string
    {
        return $this->size->columnClass();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'section' => $this->section,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel(),
            'badge_class' => $this->badgeClass(),
            'generated_at' => $this->generatedAt->toIso8601String(),
            'metrics' => array_map(
                fn (PlatformMetric $metric): array => $metric->toArray(),
                $this->metrics,
            ),
            'meta' => $this->meta,
            'detail_url' => $this->detailUrl,
            'actions' => $this->actions,
            'body_partial' => $this->bodyPartial,
            'size' => $this->size->value,
            'column_class' => $this->columnClass(),
            'refreshable' => $this->refreshable,
            'pinned' => $this->pinned,
            'sort_key' => $this->sortKey,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
        ];
    }
}
