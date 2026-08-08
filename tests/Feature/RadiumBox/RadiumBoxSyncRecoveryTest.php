<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxEnrichmentRetryPolicy;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class RadiumBoxSyncRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.recovery.enabled' => true,
            'radiumbox.recovery.stale_pending_minutes' => 30,
            'radiumbox.recovery.schedule_limit' => 50,
            'radiumbox.recovery.max_recovery_attempts' => 10,
        ]);
    }

    public function test_scheduler_recovers_failed_orders_when_safe(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markFailed($order->id, 'Connection timed out');
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => now()->subHours(2),
            'radiumbox_sync_attempts' => 1,
        ]);

        $this->travel(2)->hours();

        Artisan::call('radiumbox:recover-sync');

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $order->fresh()->radiumbox_sync_status);
    }

    public function test_scheduler_recovers_stale_pending_orders(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markPending($order->id);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_last_sync_at' => now()->subMinutes(45),
            'radiumbox_sync_attempts' => 3,
        ]);

        Artisan::call('radiumbox:recover-sync');

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id);
        // Recovery must not wipe attempt counters (max_recovery_attempts).
        $this->assertSame(3, $syncStore->attemptCount($order->id));
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $syncStore->status($order->id));
    }

    public function test_scheduler_excludes_orders_at_retry_limit_from_sql_candidates(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markFailed($order->id, 'Persistent failure');
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => now()->subHours(2),
            'radiumbox_sync_attempts' => 10,
        ]);

        $this->travel(2)->hours();

        $candidateIds = $this->eligibleCandidateIds();
        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        $this->assertNotContains($order->id, $candidateIds);
        Queue::assertNothingPushed();
        $this->assertSame(0, $result->scanned);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->recovered);
    }

    public function test_scheduler_skips_recent_pending_orders(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markPending($order->id);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_last_sync_at' => now()->subMinutes(5),
            'radiumbox_sync_attempts' => 1,
        ]);

        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        Queue::assertNothingPushed();
        $this->assertFalse(app(RadiumBoxSyncRecoveryService::class)->isStalePending($order->fresh()));
        $this->assertContains($order->id, $this->eligibleCandidateIds());
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->recovered);
    }

    public function test_a1_excludes_orders_outside_automatic_window_from_sql_candidates(): void
    {
        Queue::fake();

        $outsideWindow = $this->createRecoverableOrder([
            'created_at' => now()->subDays(RadiumBoxEnrichmentRetryPolicy::AUTOMATIC_WINDOW_DAYS + 1),
        ]);
        $insideWindow = $this->createRecoverableOrder([
            'created_at' => now()->subDays(2),
        ]);

        $this->markSyncedMissingEnrichment($outsideWindow, attempts: 2, lastSyncAt: now()->subDays(2));
        $this->markSyncedMissingEnrichment($insideWindow, attempts: 2, lastSyncAt: now()->subDays(2));

        $this->assertFalse(
            app(RadiumBoxSyncRecoveryService::class)->isSafeToRecover($outsideWindow->fresh()),
            'Outside-window Synced orders with a prior attempt must already be PHP-rejected.',
        );

        $candidateIds = $this->eligibleCandidateIds();

        $this->assertNotContains($outsideWindow->id, $candidateIds);
        $this->assertContains($insideWindow->id, $candidateIds);
    }

    public function test_a1_keeps_recent_below_max_synced_orders_as_sql_candidates(): void
    {
        $order = $this->createRecoverableOrder([
            'created_at' => now()->subHours(3),
        ]);
        $this->markSyncedMissingEnrichment($order, attempts: 4, lastSyncAt: now()->subHours(2));

        $this->assertContains($order->id, $this->eligibleCandidateIds());
        $this->assertTrue(
            app(RadiumBoxSyncRecoveryService::class)->isSafeToRecover($order->fresh()),
        );
    }

    public function test_a1_still_recovers_currently_safe_synced_orders(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder([
            'created_at' => now()->subHours(3),
        ]);
        $this->markSyncedMissingEnrichment($order, attempts: 2, lastSyncAt: now()->subHours(2));

        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        $this->assertSame(1, $result->recovered);
        $this->assertSame([$order->id], $result->recoveredOrderIds);
        Queue::assertPushed(
            RadiumBoxOrderEnrichmentJob::class,
            fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id,
        );
    }

    public function test_a1_preserves_stale_pending_outside_automatic_window(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder([
            'created_at' => now()->subDays(RadiumBoxEnrichmentRetryPolicy::AUTOMATIC_WINDOW_DAYS + 3),
        ]);
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markPending($order->id);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_last_sync_at' => now()->subMinutes(45),
            'radiumbox_sync_attempts' => 2,
        ]);

        $this->assertContains($order->id, $this->eligibleCandidateIds());

        Artisan::call('radiumbox:recover-sync');

        Queue::assertPushed(
            RadiumBoxOrderEnrichmentJob::class,
            fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id,
        );
    }

    public function test_a1_does_not_starve_newer_safe_orders_behind_excluded_rows(): void
    {
        Queue::fake();

        $excludedMaxAttempts = $this->createRecoverableOrder([
            'created_at' => now()->subHours(5),
        ]);
        $this->markSyncedMissingEnrichment(
            $excludedMaxAttempts,
            attempts: 10,
            lastSyncAt: now()->subHours(2),
        );

        $excludedOld = $this->createRecoverableOrder([
            'created_at' => now()->subDays(RadiumBoxEnrichmentRetryPolicy::AUTOMATIC_WINDOW_DAYS + 2),
        ]);
        $this->markSyncedMissingEnrichment(
            $excludedOld,
            attempts: 3,
            lastSyncAt: now()->subDays(2),
        );

        $safeNewer = $this->createRecoverableOrder([
            'created_at' => now()->subHours(3),
        ]);
        $this->markSyncedMissingEnrichment(
            $safeNewer,
            attempts: 1,
            lastSyncAt: now()->subHours(2),
        );

        $this->assertTrue($excludedMaxAttempts->id < $safeNewer->id);
        $this->assertTrue($excludedOld->id < $safeNewer->id);
        $this->assertFalse(
            app(RadiumBoxSyncRecoveryService::class)->isSafeToRecover($excludedOld->fresh()),
        );

        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        $this->assertSame(1, $result->recovered);
        $this->assertSame([$safeNewer->id], $result->recoveredOrderIds);
        $this->assertSame(1, $result->scanned);
        Queue::assertPushed(
            RadiumBoxOrderEnrichmentJob::class,
            fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $safeNewer->id,
        );
    }

    public function test_schedule_limit_remains_recovery_cap_not_scan_cap(): void
    {
        Queue::fake();
        config(['radiumbox.recovery.schedule_limit' => 2]);

        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $order = $this->createRecoverableOrder([
                'created_at' => now()->subHours(3),
            ]);
            $this->markSyncedMissingEnrichment($order, attempts: 1, lastSyncAt: now()->subHours(2));
            $orders[] = $order;
        }

        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        $this->assertSame(2, $result->recovered);
        $this->assertLessThanOrEqual(3, $result->scanned);
        $this->assertContains($orders[0]->id, $result->recoveredOrderIds);
        $this->assertContains($orders[1]->id, $result->recoveredOrderIds);
        $this->assertNotContains($orders[2]->id, $result->recoveredOrderIds);
    }

    public function test_recovery_command_respects_disabled_config(): void
    {
        config(['radiumbox.recovery.enabled' => false]);

        $this->artisan('radiumbox:recover-sync')
            ->expectsOutput('RadiumBox recovery is disabled.')
            ->assertSuccessful();
    }

    /**
     * @return list<int>
     */
    private function eligibleCandidateIds(): array
    {
        $service = app(RadiumBoxSyncRecoveryService::class);
        $method = new ReflectionMethod(RadiumBoxSyncRecoveryService::class, 'eligibleOrdersQuery');
        $method->setAccessible(true);

        /** @var \Illuminate\Database\Eloquent\Builder<Order> $query */
        $query = $method->invoke($service);

        return $query->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRecoverableOrder(array $overrides = []): Order
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $createdAt = $overrides['created_at'] ?? now()->subHours(2);
        unset($overrides['created_at']);

        $order = Order::query()->create(array_merge([
            'order_id' => 'RD-RECOVER-'.uniqid(),
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ], $overrides));

        // Eloquent timestamps can overwrite create-time created_at; persist explicitly.
        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $order->fresh();
    }

    private function markSyncedMissingEnrichment(Order $order, int $attempts, mixed $lastSyncAt): void
    {
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markSynced($order->id, [
            'last_attempt_at' => $lastSyncAt instanceof \DateTimeInterface
                ? $lastSyncAt->format('Y-m-d H:i:s')
                : (string) $lastSyncAt,
            'lookup_result' => 'synced_without_serial',
        ]);

        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'radiumbox_last_sync_at' => $lastSyncAt,
            'radiumbox_sync_attempts' => $attempts,
        ]);
    }
}
