<?php

namespace App\Services\Timeline;

use App\Data\Timeline\BusinessTimelineDayGroup;
use App\Data\Timeline\BusinessTimelineItem;
use App\Data\Timeline\BusinessTimelineViewModel;
use App\Data\TimelineEvent;
use App\Enums\BusinessMilestoneType;
use App\Enums\TimelineDayBucket;
use App\Enums\TimelineEventType;
use App\Support\AppDateFormatter;
use App\Support\Timeline\BusinessMilestoneClassifier;
use App\Support\Timeline\BusinessTimelineTitlePresenter;
use App\Support\Timeline\TimelineGroupResolver;
use App\Services\Timeline\IncomingEmailReopenTimelinePresenter;
use Illuminate\Support\Collection;

class BusinessTimelineComposer
{
    /** @var array<string, list<TimelineEvent>> */
    private array $mergedRawAttachments = [];

    public function __construct(
        private readonly BusinessMilestoneClassifier $classifier,
        private readonly BusinessTimelineTitlePresenter $titlePresenter,
    ) {}

    /**
     * @param  Collection<int, TimelineEvent>  $operatorEvents  Newest-first operator-visible events
     */
    public function compose(
        Collection $operatorEvents,
        int $offset = 0,
        int $limit = TimelineService::DEFAULT_PAGE_SIZE,
        ?string $query = null,
    ): BusinessTimelineViewModel {
        $rawEventCount = $operatorEvents->count();
        $query = filled($query) ? trim($query) : null;

        $eventsForCompose = $operatorEvents;
        if ($query !== null) {
            $needle = mb_strtolower($query);
            $eventsForCompose = $operatorEvents
                ->filter(function (TimelineEvent $event) use ($needle): bool {
                    foreach ($this->searchTokens([$event], $event->title, (string) ($event->summary ?? '')) as $token) {
                        if (str_contains(mb_strtolower($token), $needle)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();
        }

        $this->mergedRawAttachments = [];
        $eventsForCompose = $this->collapseCanonicalDuplicates($eventsForCompose);

        $milestones = $this->toMilestones($eventsForCompose);
        $clustered = $this->clusterConsecutive($milestones);
        $clustered = $this->clusterSameDaySystemUpdates($clustered);

        $totalCount = $clustered->count();
        $page = $clustered->slice($offset, $limit)->values();

        return new BusinessTimelineViewModel(
            groups: $this->groupByDay($page),
            totalCount: $totalCount,
            rawEventCount: $rawEventCount,
            loadedCount: $offset + $page->count(),
            offset: $offset,
            limit: $limit,
            hasMore: ($offset + $limit) < $totalCount,
            query: $query,
        );
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     * @return Collection<int, TimelineEvent>
     */
    private function collapseCanonicalDuplicates(Collection $events): Collection
    {
        $events = $this->collapsePayments($events);
        $events = $this->collapseSerialPairs($events);
        $events = $this->collapseAppointmentNotifications($events);

        return $events->values();
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     * @return Collection<int, TimelineEvent>
     */
    private function collapsePayments(Collection $events): Collection
    {
        $orderPayments = $events
            ->filter(fn (TimelineEvent $event): bool => $this->isPaymentOrderKey($event->dedupeKey))
            ->keyBy(fn (TimelineEvent $event): string => $this->paymentOrderScopeKey($event->dedupeKey));

        if ($orderPayments->isEmpty()) {
            return $events;
        }

        $removeKeys = [];

        foreach ($events as $event) {
            if (! str_contains($event->dedupeKey, 'payment:audit:')) {
                continue;
            }

            $scope = $this->paymentAuditScopeKey($event->dedupeKey, $orderPayments);
            $primary = $scope !== null ? $orderPayments->get($scope) : null;

            if ($primary === null && $orderPayments->count() === 1) {
                $primary = $orderPayments->first();
            }

            if ($primary === null) {
                continue;
            }

            $this->attachMergedRaw($primary->dedupeKey, $event);
            $removeKeys[] = $event->dedupeKey;
        }

        if ($removeKeys === []) {
            return $events;
        }

        return $events
            ->reject(fn (TimelineEvent $event): bool => in_array($event->dedupeKey, $removeKeys, true))
            ->values();
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     * @return Collection<int, TimelineEvent>
     */
    private function collapseSerialPairs(Collection $events): Collection
    {
        $removeKeys = [];

        foreach ($events as $event) {
            if (! preg_match('/^audit:(\d+)$/', $event->dedupeKey, $matches)) {
                continue;
            }

            $title = strtolower($event->title);
            if (! str_contains($title, 'serial')) {
                continue;
            }

            $pairedKey = "serial-assigned:{$matches[1]}";
            $paired = $events->first(fn (TimelineEvent $candidate): bool => $candidate->dedupeKey === $pairedKey);

            if ($paired === null) {
                continue;
            }

            $this->attachMergedRaw($event->dedupeKey, $paired);
            $removeKeys[] = $pairedKey;
        }

        if ($removeKeys === []) {
            return $events;
        }

        return $events
            ->reject(fn (TimelineEvent $event): bool => in_array($event->dedupeKey, $removeKeys, true))
            ->values();
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     * @return Collection<int, TimelineEvent>
     */
    private function collapseAppointmentNotifications(Collection $events): Collection
    {
        $appointments = $events
            ->filter(fn (TimelineEvent $event): bool => $event->type === TimelineEventType::Appointment)
            ->values();

        if ($appointments->isEmpty()) {
            return $events;
        }

        $removeKeys = [];

        foreach ($events as $event) {
            if ($event->type !== TimelineEventType::Notification) {
                continue;
            }

            $title = strtolower($event->title);
            if (! str_contains($title, 'appointment') || ! str_contains($title, 'book')) {
                continue;
            }

            $match = $appointments
                ->filter(function (TimelineEvent $appointment) use ($event): bool {
                    return abs($appointment->occurredAt->diffInSeconds($event->occurredAt)) <= 300;
                })
                ->sortBy(fn (TimelineEvent $appointment): int => abs($appointment->occurredAt->diffInSeconds($event->occurredAt)))
                ->first();

            if ($match === null) {
                continue;
            }

            $this->attachMergedRaw($match->dedupeKey, $event);
            $removeKeys[] = $event->dedupeKey;
        }

        if ($removeKeys === []) {
            return $events;
        }

        return $events
            ->reject(fn (TimelineEvent $event): bool => in_array($event->dedupeKey, $removeKeys, true))
            ->values();
    }

    private function attachMergedRaw(string $primaryDedupeKey, TimelineEvent $event): void
    {
        $existing = $this->mergedRawAttachments[$primaryDedupeKey] ?? [];
        $existing[] = $event;
        $this->mergedRawAttachments[$primaryDedupeKey] = $existing;
    }

    private function isPaymentOrderKey(string $dedupeKey): bool
    {
        return (bool) preg_match('/payment:order:\d+$/', $dedupeKey);
    }

    private function paymentOrderScopeKey(string $dedupeKey): string
    {
        if (preg_match('/^(.*)payment:order:(\d+)$/', $dedupeKey, $matches) !== 1) {
            return $dedupeKey;
        }

        return $matches[1].'payment:order:'.$matches[2];
    }

    /**
     * @param  Collection<string, TimelineEvent>  $orderPayments
     */
    private function paymentAuditScopeKey(string $auditDedupeKey, Collection $orderPayments): ?string
    {
        if ($orderPayments->count() === 1) {
            return $orderPayments->keys()->first();
        }

        return null;
    }

    /**
     * @param  Collection<int, TimelineEvent>  $events
     * @return Collection<int, BusinessTimelineItem>
     */
    private function toMilestones(Collection $events): Collection
    {
        return $events
            ->map(function (TimelineEvent $event): BusinessTimelineItem {
                $type = $this->classifier->classify($event);
                $mergedRaw = $this->mergedRawAttachments[$event->dedupeKey] ?? [];
                $rawEvents = array_values(array_merge([$event], $mergedRaw));
                $presented = $this->titlePresenter->present($type, $rawEvents, false);

                $unified = $event->storyKey === IncomingEmailReopenTimelinePresenter::STORY_KEY;

                return new BusinessTimelineItem(
                    id: 'm:'.$event->dedupeKey,
                    type: $type,
                    occurredAt: $event->occurredAt,
                    title: $presented['title'],
                    summary: $presented['summary'],
                    rawCount: count($rawEvents),
                    rawEvents: $rawEvents,
                    searchText: $this->searchTokens($rawEvents, $presented['title'], $presented['summary']),
                    filterTags: $event->allFilterTags(),
                    isCluster: false,
                    displayFields: $unified ? $event->summaryFields : [],
                    actionBadges: $event->actionBadges,
                    technicalFields: $event->technicalFields,
                    unifiedPresentation: $unified,
                    iconClass: str_starts_with($event->dedupeKey, 'incoming_email:')
                        ? 'bi-envelope'
                        : null,
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, BusinessTimelineItem>  $items  Newest-first
     * @return Collection<int, BusinessTimelineItem>
     */
    private function clusterConsecutive(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $result = [];
        $buffer = [];

        $flush = function () use (&$result, &$buffer): void {
            if ($buffer === []) {
                return;
            }

            $result[] = $this->mergeBuffer($buffer);
            $buffer = [];
        };

        foreach ($items as $item) {
            /** @var BusinessTimelineItem $item */
            if ($buffer === []) {
                $buffer[] = $item;
                continue;
            }

            $head = $buffer[0];
            if ($this->canClusterWith($head, $item)) {
                $buffer[] = $item;
                continue;
            }

            $flush();
            $buffer[] = $item;
        }

        $flush();

        return collect($result)->values();
    }

    /**
     * @param  Collection<int, BusinessTimelineItem>  $items  Newest-first
     * @return Collection<int, BusinessTimelineItem>
     */
    private function clusterSameDaySystemUpdates(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $byDay = [];
        foreach ($items->values() as $index => $item) {
            if ($item->type !== BusinessMilestoneType::SystemUpdate) {
                continue;
            }

            $day = AppDateFormatter::inAppTimezone($item->occurredAt)?->toDateString();
            if ($day === null) {
                continue;
            }

            $byDay[$day][] = $index;
        }

        $removeIndices = [];
        $replacements = [];

        foreach ($byDay as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            $toMerge = array_map(fn (int $index): BusinessTimelineItem => $items[$index], $indices);
            $newestIndex = min($indices);
            $replacements[$newestIndex] = $this->mergeBuffer($toMerge);

            foreach ($indices as $index) {
                if ($index !== $newestIndex) {
                    $removeIndices[] = $index;
                }
            }
        }

        if ($removeIndices === []) {
            return $items;
        }

        $result = [];
        foreach ($items->values() as $index => $item) {
            if (in_array($index, $removeIndices, true)) {
                continue;
            }

            $result[] = $replacements[$index] ?? $item;
        }

        return collect($result)->values();
    }

    private function canClusterWith(BusinessTimelineItem $a, BusinessTimelineItem $b): bool
    {
        if ($a->type !== $b->type || ! $a->type->allowsClustering()) {
            return false;
        }

        $dayA = AppDateFormatter::inAppTimezone($a->occurredAt)?->toDateString();
        $dayB = AppDateFormatter::inAppTimezone($b->occurredAt)?->toDateString();

        if ($dayA === null || $dayB === null || $dayA !== $dayB) {
            return false;
        }

        $familyA = $this->classifier->clusterFamily($a->rawEvents[0], $a->type);
        $familyB = $this->classifier->clusterFamily($b->rawEvents[0], $b->type);

        return $familyA === $familyB;
    }

    /**
     * @param  list<BusinessTimelineItem>  $buffer  Newest-first members
     */
    private function mergeBuffer(array $buffer): BusinessTimelineItem
    {
        if (count($buffer) === 1) {
            return $buffer[0];
        }

        $rawEvents = [];
        foreach ($buffer as $item) {
            foreach ($item->rawEvents as $event) {
                $rawEvents[] = $event;
            }
        }

        $type = $buffer[0]->type;
        $presented = $this->titlePresenter->present($type, $rawEvents, true);
        $filterTags = [];
        foreach ($rawEvents as $event) {
            $filterTags = [...$filterTags, ...$event->allFilterTags()];
        }

        $keys = array_map(fn (TimelineEvent $event): string => $event->dedupeKey, $rawEvents);

        return new BusinessTimelineItem(
            id: 'c:'.hash('sha256', implode('|', $keys)),
            type: $type,
            occurredAt: $buffer[0]->occurredAt,
            title: $presented['title'],
            summary: $presented['summary'],
            rawCount: count($rawEvents),
            rawEvents: $rawEvents,
            searchText: $this->searchTokens($rawEvents, $presented['title'], $presented['summary']),
            filterTags: array_values(array_unique($filterTags)),
            isCluster: true,
            displayFields: [],
            actionBadges: [],
            technicalFields: [],
            unifiedPresentation: false,
            iconClass: $buffer[0]->iconClass,
        );
    }

    /**
     * @param  Collection<int, BusinessTimelineItem>  $items
     * @return Collection<int, BusinessTimelineDayGroup>
     */
    private function groupByDay(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $reference = AppDateFormatter::inAppTimezone(now()) ?? now();

        return $items
            ->groupBy(function (BusinessTimelineItem $item) use ($reference): string {
                return TimelineGroupResolver::resolve($item->occurredAt, $reference)['key'];
            })
            ->map(function (Collection $groupItems, string $groupKey) use ($reference): BusinessTimelineDayGroup {
                $first = $groupItems->first();
                $resolved = TimelineGroupResolver::resolve($first->occurredAt, $reference);

                return new BusinessTimelineDayGroup(
                    bucket: $this->bucketForGroupKey($groupKey),
                    items: $groupItems->values(),
                    displayLabel: $resolved['label'],
                    sortKey: $resolved['sort_key'],
                );
            })
            ->sortBy(fn (BusinessTimelineDayGroup $group) => $group->sortKey)
            ->values();
    }

    private function bucketForGroupKey(string $groupKey): TimelineDayBucket
    {
        return match (true) {
            $groupKey === 'today' => TimelineDayBucket::Today,
            $groupKey === 'yesterday' => TimelineDayBucket::Yesterday,
            default => TimelineDayBucket::Earlier,
        };
    }

    /**
     * @param  list<TimelineEvent>  $events
     * @return list<string>
     */
    private function searchTokens(array $events, string $title, string $summary): array
    {
        $tokens = [$title, $summary];

        foreach ($events as $event) {
            $tokens[] = $event->title;
            $tokens[] = (string) $event->summary;
            $tokens[] = (string) $event->detail;
            $tokens[] = (string) $event->noteBody;
            $tokens[] = (string) $event->contextLine;
            $tokens[] = $event->actor->displayName;
            $tokens[] = (string) $event->actor->subtitle;

            foreach ($event->summaryFields as $field) {
                $tokens[] = (string) ($field['label'] ?? '');
                $tokens[] = (string) ($field['value'] ?? '');
            }

            foreach ($event->technicalFields as $field) {
                $tokens[] = (string) ($field['label'] ?? '');
                $tokens[] = (string) ($field['value'] ?? '');
            }

            foreach ($event->actionBadges as $badge) {
                $tokens[] = $badge;
            }

            foreach ($event->communicationChannels as $channel) {
                $tokens[] = (string) ($channel['label'] ?? '');
                $tokens[] = (string) ($channel['detail'] ?? '');
            }

            foreach ($event->mentionedUserNames as $name) {
                $tokens[] = $name;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $token): string => trim($token), $tokens),
            static fn (string $token): bool => $token !== '',
        )));
    }
}
