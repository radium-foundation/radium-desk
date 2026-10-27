<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryAvailabilitySyncLog;
use App\Models\InventoryProduct;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityRefreshScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_and_prune_are_scheduled_without_overlap(): void
    {
        $refresh = $this->event('radiumbox:backfill-catalog-availability');
        $this->assertSame('2-59/10 * * * *', $refresh->getExpression());
        $this->assertTrue($refresh->withoutOverlapping);
        $this->assertSame([2, 12, 22, 32, 42, 52], $this->dueMinutes($refresh->getExpression()));

        $prune = $this->event('radiumbox:prune-availability-sync-logs');
        $this->assertSame('40 3 * * *', $prune->getExpression());
        $this->assertTrue($prune->withoutOverlapping);

        $product = InventoryProduct::query()->create([
            'sku' => 'RB-PRUNE',
            'name' => 'Prune',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_active' => true,
        ]);

        $recent = InventoryAvailabilitySyncLog::query()->create([
            'inventory_product_id' => $product->id,
            'radiumbox_model_id' => 945,
            'available_qty' => 4,
            'inventory_tracking' => 'quantity_tracked',
            'idempotency_key' => 'recent-key',
            'requested_at' => now(),
            'status' => 'synced',
        ]);
        $old = InventoryAvailabilitySyncLog::query()->create([
            'inventory_product_id' => $product->id,
            'radiumbox_model_id' => 945,
            'available_qty' => 4,
            'inventory_tracking' => 'quantity_tracked',
            'idempotency_key' => 'old-key',
            'requested_at' => now()->subDays(20),
            'status' => 'synced',
        ]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(20)])->save();

        $this->artisan('radiumbox:prune-availability-sync-logs', ['--days' => 14])->assertSuccessful();

        $this->assertDatabaseHas('inventory_availability_sync_logs', ['id' => $recent->id]);
        $this->assertDatabaseMissing('inventory_availability_sync_logs', ['id' => $old->id]);
    }

    private function event(string $needle): Event
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(function (Event $event) use ($needle): bool {
            return str_contains((string) ($event->command ?? ''), $needle);
        });

        $this->assertInstanceOf(
            Event::class,
            $event,
            'Missing '.$needle.' among: '.$events->map(fn (Event $event): string => (string) ($event->command ?? ''))->implode(' | '),
        );

        return $event;
    }

    /**
     * @return list<int>
     */
    private function dueMinutes(string $expression): array
    {
        $cron = new CronExpression($expression);
        $minutes = [];
        for ($minute = 0; $minute < 60; $minute++) {
            $at = new \DateTimeImmutable(sprintf('2026-10-19 08:%02d:00', $minute));
            if ($cron->isDue($at)) {
                $minutes[] = $minute;
            }
        }

        return $minutes;
    }
}
