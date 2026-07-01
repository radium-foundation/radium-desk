<?php

namespace App\Data;

use App\Enums\TimelineEventType;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;

readonly class TimelineEvent
{
    public const DETAIL_COLLAPSE_THRESHOLD = 120;

    public function __construct(
        public TimelineEventType $type,
        public Carbon $occurredAt,
        public string $title,
        public TimelineActor $actor,
        public string $dedupeKey,
        public ?string $summary = null,
        public ?string $detail = null,
        public ?string $statusLabel = null,
        public ?string $statusVariant = null,
    ) {}

    public function filterCategory(): string
    {
        return $this->type->filterCategory();
    }

    public function iconClass(): string
    {
        return $this->type->icon();
    }

    public function relativeTimestamp(): string
    {
        return AppDateFormatter::timelineRelative($this->occurredAt) ?? '—';
    }

    public function exactTimestamp(): string
    {
        return AppDateFormatter::timelineDatetime($this->occurredAt) ?? '—';
    }

    public function isDetailExpandable(): bool
    {
        return $this->detail !== null
            && mb_strlen($this->detail) > self::DETAIL_COLLAPSE_THRESHOLD;
    }

    public function collapsedDetailPreview(): ?string
    {
        if ($this->detail === null) {
            return null;
        }

        if (! $this->isDetailExpandable()) {
            return $this->detail;
        }

        return mb_substr($this->detail, 0, self::DETAIL_COLLAPSE_THRESHOLD).'…';
    }
}
