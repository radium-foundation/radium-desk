<?php

namespace Tests\Feature;

use App\Data\Operations\ProductionCriticalAlert;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\ProductionWatchdogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WatchdogAlertParityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['localhost/*' => Http::response('OK', 200)]);
        config([
            'ira.watchdog.radiumbox_hygiene_enabled' => false,
            'ira.watchdog.dead_letter_hygiene_enabled' => false,
            'ira.watchdog.bonvoice_hygiene_enabled' => false,
            'ira.watchdog.actionable_hours' => 48,
            'ira.watchdog.stale_days' => 30,
            'infrastructure.queue_worker_mode' => 'dedicated_cron',
            'radiumbox.enabled' => true,
        ]);
    }

    public function test_command_outputs_flags_buckets_and_legacy_vs_hygiene_counts(): void
    {
        Queue::fake();

        $this->createFailedOrder('RD900100', now()->subHours(2));
        $this->createFailedOrder('CFPay_noise', now()->subHours(2));
        $this->createFailedOrder('RD900200', now()->subDays(40));

        $watchdog = app(ProductionWatchdogService::class);
        $this->assertSame(3, $this->firstAffectedCount($watchdog->collectParityAlerts(false), 'radiumbox:sync_failures'));
        $this->assertSame(1, $this->firstAffectedCount($watchdog->collectParityAlerts(true), 'radiumbox:sync_failures'));

        $this->artisan('watchdog:alert-parity')
            ->expectsOutputToContain('deployed_feature_flags radiumbox=false')
            ->expectsOutputToContain('parity_compare_mode legacy=collectParityAlerts(false)')
            ->expectsOutputToContain('radiumbox:sync_failures legacy_critical_count=3')
            ->expectsOutputToContain('radiumbox_buckets actionable_48h=')
            ->expectsOutputToContain('dead_letter_buckets')
            ->expectsOutputToContain('bonvoice_terminal_24h')
            ->expectsOutputToContain('watchdog_hygiene_metrics=')
            ->assertSuccessful();
    }

    public function test_command_does_not_dispatch_notifications(): void
    {
        Queue::fake();

        $this->artisan('watchdog:alert-parity')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * @param  list<ProductionCriticalAlert>  $alerts
     */
    private function firstAffectedCount(array $alerts, string $key): int
    {
        foreach ($alerts as $alert) {
            if ($alert->key === $key) {
                return (int) $alert->affectedCount;
            }
        }

        return 0;
    }

    private function createFailedOrder(string $orderId, Carbon $lastSyncAt): Order
    {
        $actor = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Watchdog Parity Command Test',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => $lastSyncAt,
        ]);
    }
}
