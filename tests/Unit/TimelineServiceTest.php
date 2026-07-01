<?php

namespace Tests\Unit;

use App\Data\TimelineEvent;
use App\Data\TimelineViewModel;
use App\Enums\TimelineDayBucket;
use App\Enums\TimelineEventType;
use App\Data\TimelineActor;
use App\Contracts\Timeline\TimelineEventSource;
use App\Services\Timeline\TimelineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TimelineServiceTest extends TestCase
{
    public function test_build_groups_events_into_today_yesterday_and_earlier(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 14:00:00', 'Asia/Kolkata'));

        $source = new class implements TimelineEventSource
        {
            public function collect(): Collection
            {
                return collect([
                    $this->event('today', now()),
                    $this->event('yesterday', now()->subDay()->setTime(10, 0)),
                    $this->event('earlier', now()->subDays(3)),
                ]);
            }

            private function event(string $key, Carbon $occurredAt): TimelineEvent
            {
                return new TimelineEvent(
                    type: TimelineEventType::AuditEvent,
                    occurredAt: $occurredAt,
                    title: $key,
                    actor: new TimelineActor('Agent'),
                    dedupeKey: $key,
                );
            }
        };

        $viewModel = app(TimelineService::class)->build([$source], limit: 10);

        $this->assertInstanceOf(TimelineViewModel::class, $viewModel);
        $this->assertSame(3, $viewModel->totalCount);
        $this->assertFalse($viewModel->hasMore);

        $labels = $viewModel->groups->map(fn ($group) => $group->bucket)->all();

        $this->assertSame([
            TimelineDayBucket::Today,
            TimelineDayBucket::Yesterday,
            TimelineDayBucket::Earlier,
        ], $labels);
    }

    public function test_build_paginates_and_reports_has_more(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 14:00:00', 'Asia/Kolkata'));

        $source = new class implements TimelineEventSource
        {
            public function collect(): Collection
            {
                return collect(range(1, 5))->map(fn (int $index) => new TimelineEvent(
                    type: TimelineEventType::AuditEvent,
                    occurredAt: now()->subMinutes($index),
                    title: "Event {$index}",
                    actor: new TimelineActor('Agent'),
                    dedupeKey: "event-{$index}",
                ));
            }
        };

        $firstPage = app(TimelineService::class)->build([$source], offset: 0, limit: 2);
        $secondPage = app(TimelineService::class)->build([$source], offset: 2, limit: 2);

        $this->assertTrue($firstPage->hasMore);
        $this->assertSame(2, $firstPage->loadedCount);
        $this->assertSame(2, $firstPage->events()->count());

        $this->assertTrue($secondPage->hasMore);
        $this->assertSame(4, $secondPage->loadedCount);
    }

    public function test_merge_sources_deduplicates_by_key(): void
    {
        $duplicate = new TimelineEvent(
            type: TimelineEventType::Payment,
            occurredAt: now(),
            title: 'Payment received',
            actor: new TimelineActor('Ira'),
            dedupeKey: 'payment:1',
        );

        $sourceOne = new class($duplicate) implements TimelineEventSource
        {
            public function __construct(private readonly TimelineEvent $event) {}

            public function collect(): Collection
            {
                return collect([$this->event]);
            }
        };

        $sourceTwo = new class($duplicate) implements TimelineEventSource
        {
            public function __construct(private readonly TimelineEvent $event) {}

            public function collect(): Collection
            {
                return collect([$this->event]);
            }
        };

        $merged = app(TimelineService::class)->mergeSources([$sourceOne, $sourceTwo]);

        $this->assertCount(1, $merged);
    }
}
