<?php

namespace Tests\Unit\Timeline;

use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Services\Timeline\BusinessTimelineComposer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessTimelineComposerTest extends TestCase
{
    public function test_clusters_same_day_whatsapp_reminders(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 18:00:00', 'Asia/Kolkata'));

        $events = collect([
            $this->whatsApp(1, now()->subMinutes(10)),
            $this->whatsApp(2, now()->subMinutes(20)),
            $this->whatsApp(3, now()->subMinutes(30)),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);

        $this->assertSame(1, $viewModel->totalCount);
        $this->assertSame(3, $viewModel->rawEventCount);

        $item = $viewModel->items()->first();
        $this->assertNotNull($item);
        $this->assertTrue($item->isCluster);
        $this->assertSame(3, $item->rawCount);
        $this->assertCount(3, $item->rawEvents);
        $this->assertSame('3 WhatsApp reminders', $item->title);
        $this->assertStringNotContainsString('whatsapp_template_sent', $item->title);
    }

    public function test_does_not_cluster_across_milestone_types(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 18:00:00', 'Asia/Kolkata'));

        $events = collect([
            new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: now()->subMinutes(5),
                title: 'Payment received',
                actor: new TimelineActor('System'),
                dedupeKey: 'payment:1',
            ),
            $this->whatsApp(1, now()->subMinutes(10)),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);

        $this->assertSame(2, $viewModel->totalCount);
    }

    public function test_search_filters_raw_events_before_clustering(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 18:00:00', 'Asia/Kolkata'));

        $events = collect([
            $this->whatsApp(1, now()->subMinutes(10), 'Serial reminder', '9876543210'),
            $this->whatsApp(2, now()->subMinutes(20), 'Payment reminder', 'payment due'),
            new TimelineEvent(
                type: TimelineEventType::InternalNote,
                occurredAt: now()->subMinutes(15),
                title: 'Internal note',
                actor: new TimelineActor('Agent'),
                dedupeKey: 'note:1',
                noteBody: 'Spoke with Sushant about serial',
            ),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events, query: 'serial');

        $titles = $viewModel->items()->map(fn ($item) => $item->title)->all();

        $this->assertNotEmpty($titles);
        $this->assertTrue(
            collect($titles)->contains(fn (string $title): bool => str_contains(strtolower($title), 'whatsapp')
                || str_contains(strtolower($title), 'note')),
        );
        $this->assertFalse(
            collect($titles)->contains(fn (string $title): bool => str_contains(strtolower($title), 'payment')),
        );
    }

    public function test_paginates_by_milestone_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 18:00:00', 'Asia/Kolkata'));

        $events = collect(range(1, 5))->map(fn (int $i) => new TimelineEvent(
            type: TimelineEventType::Payment,
            occurredAt: now()->subMinutes($i),
            title: "Payment {$i}",
            actor: new TimelineActor('System'),
            dedupeKey: "payment:{$i}",
        ));

        $page = app(BusinessTimelineComposer::class)->compose($events, offset: 0, limit: 2);

        $this->assertSame(5, $page->totalCount);
        $this->assertSame(2, $page->loadedCount);
        $this->assertTrue($page->hasMore);
        $this->assertSame(2, $page->items()->count());
    }

    private function whatsApp(int $index, Carbon $at, string $title = 'whatsapp_template_sent', string $summary = 'Reminder'): TimelineEvent
    {
        return new TimelineEvent(
            type: TimelineEventType::WhatsAppTemplateSent,
            occurredAt: $at,
            title: $title,
            actor: new TimelineActor('IRA', isAutomation: true),
            dedupeKey: "wa:{$index}",
            summary: $summary,
            filterTags: ['notifications'],
            summaryFields: [
                ['label' => 'Template', 'value' => $summary],
            ],
        );
    }
}
