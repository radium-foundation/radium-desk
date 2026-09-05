<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillRadiumBoxSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-27 12:00:00');

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.admin_fallback_enabled' => true,
            'radiumbox.recovery.enabled' => true,
            'radiumbox.recovery.stale_pending_minutes' => 30,
            'radiumbox.recovery.max_recovery_attempts' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('radiumbox:backfill-sync --help')
            ->assertSuccessful();
    }

    public function test_dry_run_dispatches_nothing_and_reports_counts(): void
    {
        Log::spy();
        Queue::fake();

        $agent = User::factory()->create();
        $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-BACKFILL-1');
        $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-BACKFILL-2');

        $this->artisan('radiumbox:backfill-sync --dry-run')
            ->expectsOutputToContain('orders scanned: 2')
            ->expectsOutputToContain('orders would process: 2')
            ->expectsOutputToContain('orders skipped: 0')
            ->expectsOutputToContain('orders failed: 0')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'RadiumBox sync backfill completed.'
                    && ($context['dry_run'] ?? null) === true
                    && ($context['would_process'] ?? null) === 2
                    && ($context['processed'] ?? null) === 0;
            });
    }

    public function test_dispatches_enrichment_for_orders_missing_details(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-DISPATCH');

        $this->artisan('radiumbox:backfill-sync --limit=10 --chunk=25')
            ->expectsOutputToContain('orders processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id),
        );
    }

    public function test_skips_non_stale_pending_and_is_idempotent_after_dispatch(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-PENDING');
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($order->id);

        $this->assertSame(0, Artisan::call('radiumbox:backfill-sync --order=RD-SYNC-PENDING'));
        $output = Artisan::output();
        $this->assertStringContainsString('orders processed: 0', $output);
        $this->assertMatchesRegularExpression('/orders skipped: [1-9]\d*/', $output);
        Queue::assertNothingPushed();

        $this->assertSame(0, Artisan::call('radiumbox:backfill-sync --order=RD-SYNC-PENDING'));
        $output = Artisan::output();
        $this->assertStringContainsString('orders processed: 0', $output);
        $this->assertMatchesRegularExpression('/orders skipped: [1-9]\d*/', $output);
        Queue::assertNothingPushed();
    }

    public function test_retries_stale_pending_enrichment(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-STALE');
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markPending($order->id);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_last_sync_at' => now()->subMinutes(45),
        ]);

        Carbon::setTestNow('2026-07-27 13:00:00');

        $this->artisan('radiumbox:backfill-sync')
            ->expectsOutputToContain('orders processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_retries_failed_orders_when_safe(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-FAILED');
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markFailed($order->id, 'Connection timed out');
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => now()->subHours(2),
            'radiumbox_sync_attempts' => 1,
        ]);

        $this->travel(2)->hours();

        $this->artisan('radiumbox:backfill-sync')
            ->expectsOutputToContain('orders processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id);
    }

    public function test_respects_limit(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-LIMIT-1');
        $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-LIMIT-2');
        $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-LIMIT-3');

        $this->artisan('radiumbox:backfill-sync --limit=2 --chunk=10')
            ->expectsOutputToContain('orders processed: 2')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 2);
    }

    public function test_single_order_option(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-SINGLE');
        $this->createMissingEnrichmentOrder($agent, 'RD-SYNC-OTHER');

        $this->artisan('radiumbox:backfill-sync --order=RD-SYNC-SINGLE')
            ->expectsOutputToContain('orders processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    private function createMissingEnrichmentOrder(User $agent, string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'device_model' => null,
            'product_name' => null,
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $agent->id,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'created_at' => now()->subDay(),
        ]);
    }
}
