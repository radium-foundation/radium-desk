<?php

namespace Tests\Unit\RadiumBox;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use App\Services\RadiumBox\RadiumBoxSyncTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RadiumBoxSyncTimelineServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_multiple_scheduler_recoveries_collapse_into_single_entry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:45:00', 'Asia/Kolkata'));

        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-COLLAPSE',
            'status' => 'active',
            'created_by' => $agent->id,
            'created_at' => now()->subDays(2),
        ]);

        foreach (range(1, 7) as $index) {
            AuditLog::query()->create([
                'event' => RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY,
                'auditable_type' => $order->getMorphClass(),
                'auditable_id' => $order->id,
                'old_values' => [],
                'new_values' => ['sync_source' => 'scheduler'],
                'created_at' => now()->subHours(8 - $index),
            ]);
        }

        $entries = app(RadiumBoxSyncTimelineService::class)->forOrder($order->fresh());

        $recoveryEntries = collect($entries)->filter(
            fn (array $entry): bool => str_contains((string) ($entry['title'] ?? ''), 'Scheduler Recovery'),
        );

        $this->assertCount(1, $recoveryEntries);

        $recovery = $recoveryEntries->first();

        $this->assertSame('Scheduler Recovery (7 attempts)', $recovery['title']);
        $this->assertSame('Last attempt: 10 Jul 11:45 PM', $recovery['subtitle']);
    }

    public function test_manual_retry_events_remain_visible_between_scheduler_recoveries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create(['name' => 'Sync Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-MANUAL',
            'status' => 'active',
            'created_by' => $agent->id,
            'created_at' => now()->subDay(),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'old_values' => [],
            'new_values' => ['sync_source' => 'scheduler'],
            'created_at' => now()->subHours(4),
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => RadiumBoxSyncAuditService::EVENT_MANUAL_SYNC,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'old_values' => [],
            'new_values' => ['sync_source' => 'manual', 'success' => false],
            'created_at' => now()->subHours(3),
        ]);

        AuditLog::query()->create([
            'event' => RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'old_values' => [],
            'new_values' => ['sync_source' => 'scheduler'],
            'created_at' => now()->subHours(2),
        ]);

        AuditLog::query()->create([
            'event' => RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'old_values' => [],
            'new_values' => ['sync_source' => 'scheduler'],
            'created_at' => now()->subHour(),
        ]);

        $entries = app(RadiumBoxSyncTimelineService::class)->forOrder($order->fresh());
        $titles = collect($entries)->pluck('title')->all();

        $this->assertContains('Manual Retry', $titles);
        $this->assertContains('Scheduler Recovery', $titles);
        $this->assertContains('Scheduler Recovery (2 attempts)', $titles);
        $this->assertSame(2, collect($titles)->filter(
            fn (string $title): bool => str_contains($title, 'Scheduler Recovery'),
        )->count());
    }
}
