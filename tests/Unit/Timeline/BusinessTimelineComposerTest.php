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
            $this->whatsApp(1, now()->subMinutes(10)),
            new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: now()->subMinutes(5),
                title: 'Payment received',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'payment:1',
            ),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);

        $this->assertSame(2, $viewModel->totalCount);
    }

    public function test_case_closure_milestone_uses_closure_title_not_case_created(): void
    {
        $event = new TimelineEvent(
            type: TimelineEventType::ServiceCaseClosed,
            occurredAt: Carbon::parse('2026-07-29 15:47:59', 'Asia/Kolkata'),
            title: 'Incident closed',
            actor: new TimelineActor('Avinash'),
            dedupeKey: 'incident-status:99',
        );

        $viewModel = app(BusinessTimelineComposer::class)->compose(collect([$event]));

        $item = $viewModel->items()->first();
        $this->assertNotNull($item);
        $this->assertSame('Case closed.', $item->title);
        $this->assertNotSame('Customer created service request.', $item->title);
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

    public function test_payment_audit_only_produces_single_milestone_without_order_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00', 'Asia/Kolkata'));

        $at = Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata');
        $events = collect([
            new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: $at,
                title: 'Payment received',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'payment:audit:1097216',
            ),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);

        $this->assertSame(1, $viewModel->totalCount);
        $item = $viewModel->items()->first();
        $this->assertNotNull($item);
        $this->assertSame('Payment received.', $item->title);
        $this->assertCount(1, $item->rawEvents);
        $this->assertSame('payment:audit:1097216', $item->rawEvents[0]->dedupeKey);
        $this->assertFalse(
            collect($item->rawEvents)->contains(fn (TimelineEvent $event): bool => str_contains($event->dedupeKey, 'payment:order:')),
        );
    }

    public function test_collapses_payment_order_and_audit_into_single_milestone_with_raw_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00', 'Asia/Kolkata'));

        $at = Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata');
        $events = collect([
            new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: $at,
                title: 'Payment received',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'payment:audit:1097216',
            ),
            new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: $at->copy()->subSeconds(33),
                title: 'Payment received',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'payment:order:34281',
            ),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);

        $this->assertSame(1, $viewModel->totalCount);
        $item = $viewModel->items()->first();
        $this->assertNotNull($item);
        $this->assertSame('Payment received.', $item->title);
        $this->assertCount(2, $item->rawEvents);
        $this->assertSame('payment:order:34281', $item->rawEvents[0]->dedupeKey);
        $this->assertSame('payment:audit:1097216', $item->rawEvents[1]->dedupeKey);
    }

    public function test_collapses_serial_audit_and_sync_pair_into_single_milestone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:15:00', 'Asia/Kolkata'));

        $at = Carbon::parse('2026-08-11 10:12:53', 'Asia/Kolkata');
        $events = collect([
            new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $at,
                title: 'Serial assigned',
                actor: new TimelineActor('Agent'),
                dedupeKey: 'serial-assigned:1100666',
            ),
            new TimelineEvent(
                type: TimelineEventType::AuditEvent,
                occurredAt: $at,
                title: 'Serial Number Added',
                actor: new TimelineActor('Agent'),
                dedupeKey: 'audit:1100666',
            ),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);

        $this->assertSame(1, $viewModel->totalCount);
        $item = $viewModel->items()->first();
        $this->assertNotNull($item);
        $this->assertSame('Serial number verified.', $item->title);
        $this->assertCount(2, $item->rawEvents);
    }

    public function test_clusters_same_day_system_updates_across_non_consecutive_milestones(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 18:00:00', 'Asia/Kolkata'));

        $events = collect([
            new TimelineEvent(
                type: TimelineEventType::AuditEvent,
                occurredAt: Carbon::parse('2026-08-11 10:33:34', 'Asia/Kolkata'),
                title: 'Transaction ID added',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'audit:1103695',
            ),
            new TimelineEvent(
                type: TimelineEventType::Assignment,
                occurredAt: Carbon::parse('2026-08-11 10:15:44', 'Asia/Kolkata'),
                title: 'Reassigned to Vanshika',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'audit:1101260',
            ),
            new TimelineEvent(
                type: TimelineEventType::AuditEvent,
                occurredAt: Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata'),
                title: 'Device Model Assigned',
                actor: new TimelineActor('IRA'),
                dedupeKey: 'audit:1097215',
            ),
        ]);

        $viewModel = app(BusinessTimelineComposer::class)->compose($events);
        $titles = $viewModel->items()->pluck('title')->all();

        $this->assertContains('2 system updates', $titles);
        $this->assertNotContains('Device Model Assigned.', $titles);
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
