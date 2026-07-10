<?php

namespace App\Services\Timeline;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineEvent;
use Illuminate\Support\Collection;

class PrefixedTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly TimelineEventSource $source,
        private readonly string $prefix,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        return $this->source->collect($limit)
            ->map(function (TimelineEvent $event): TimelineEvent {
                return new TimelineEvent(
                    type: $event->type,
                    occurredAt: $event->occurredAt,
                    title: $event->title,
                    actor: $event->actor,
                    dedupeKey: $this->prefix.$event->dedupeKey,
                    summary: $event->summary,
                    detail: $event->detail,
                    statusLabel: $event->statusLabel,
                    statusVariant: $event->statusVariant,
                    noteBody: $event->noteBody,
                    mentionedUserNames: $event->mentionedUserNames,
                    summaryFields: $event->summaryFields,
                    actionLabel: $event->actionLabel,
                    actionUrl: $event->actionUrl,
                    filterTags: $event->filterTags,
                );
            });
    }
}
