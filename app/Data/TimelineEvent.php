<?php

namespace App\Data;

use App\Enums\TimelineEventType;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;

readonly class TimelineEvent
{
    public const DETAIL_COLLAPSE_THRESHOLD = 120;

    public const INTERNAL_NOTE_TITLE = '📝 Internal Note';

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
        public ?string $noteBody = null,
        /** @var list<string> */
        public array $mentionedUserNames = [],
        /** @var list<array{label: string, value: string}> */
        public array $summaryFields = [],
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        /** @var list<string> */
        public array $filterTags = [],
        public bool $operatorVisible = true,
        public ?string $contextLine = null,
        /** @var list<array{label: string, success: bool, detail?: string}> */
        public array $communicationChannels = [],
        public ?string $indicatorVariant = null,
        public ?string $storyKey = null,
    ) {}

    public function withOperatorPresentation(
        ?string $title = null,
        ?string $contextLine = null,
        ?TimelineActor $actor = null,
        ?bool $operatorVisible = null,
        ?array $communicationChannels = null,
        ?string $indicatorVariant = null,
        ?array $summaryFields = null,
        ?string $statusLabel = null,
        ?string $statusVariant = null,
        ?string $storyKey = null,
    ): self {
        return new self(
            type: $this->type,
            occurredAt: $this->occurredAt,
            title: $title ?? $this->title,
            actor: $actor ?? $this->actor,
            dedupeKey: $this->dedupeKey,
            summary: $this->summary,
            detail: $this->detail,
            statusLabel: $statusLabel ?? $this->statusLabel,
            statusVariant: $statusVariant ?? $this->statusVariant,
            noteBody: $this->noteBody,
            mentionedUserNames: $this->mentionedUserNames,
            summaryFields: $summaryFields ?? $this->summaryFields,
            actionLabel: $this->actionLabel,
            actionUrl: $this->actionUrl,
            filterTags: $this->filterTags,
            operatorVisible: $operatorVisible ?? $this->operatorVisible,
            contextLine: $contextLine ?? $this->contextLine,
            communicationChannels: $communicationChannels ?? $this->communicationChannels,
            indicatorVariant: $indicatorVariant ?? $this->indicatorVariant,
            storyKey: $storyKey ?? $this->storyKey,
        );
    }

    public function filterCategory(): string
    {
        return $this->type->filterCategory();
    }

    /**
     * @return list<string>
     */
    public function allFilterTags(): array
    {
        $tags = array_values(array_unique(array_filter([
            $this->filterCategory(),
            $this->actor->actorFilterTag(),
            ...$this->filterTags,
        ])));

        return $tags;
    }

    public function matchesFilter(string $filterKey): bool
    {
        if ($filterKey === 'all') {
            return true;
        }

        return in_array($filterKey, $this->allFilterTags(), true);
    }

    public function iconClass(): string
    {
        return $this->type->icon();
    }

    public function relativeTimestamp(): string
    {
        return AppDateFormatter::timelineOperatorRelative($this->occurredAt) ?? '—';
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
