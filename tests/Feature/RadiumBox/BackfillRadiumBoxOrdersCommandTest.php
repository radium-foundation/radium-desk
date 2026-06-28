<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\DeviceModel;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillRadiumBoxOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('radiumbox:backfill-orders --help')
            ->assertSuccessful();
    }

    public function test_dry_run_queues_nothing_and_reports_would_queue_count(): void
    {
        Log::spy();
        Queue::fake();

        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-BACKFILL-1', null, null);
        $this->createOrder($agent, 'RD-BACKFILL-2', 'SERIAL-1', 'Model A');
        $this->createOrder($agent, 'RD-BACKFILL-3', 'SERIAL-2', null);

        $this->artisan('radiumbox:backfill-orders --dry-run')
            ->expectsOutputToContain('Orders scanned: 2')
            ->expectsOutputToContain('Orders would queue: 2')
            ->expectsOutputToContain('Orders already complete: 0')
            ->expectsOutputToContain('Immediate API calls: 0')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'RadiumBox order backfill completed.'
                    && ($context['dry_run'] ?? null) === true
                    && ($context['orders_would_queue'] ?? null) === 2
                    && ($context['orders_already_complete'] ?? null) === 0;
            });
    }

    public function test_command_dispatches_existing_enrichment_job_for_qualifying_orders(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD-BACKFILL-QUEUE', null, null);

        $this->artisan('radiumbox:backfill-orders')
            ->expectsOutputToContain('Orders scanned: 1')
            ->expectsOutputToContain('Orders queued: 1')
            ->expectsOutputToContain('API jobs queued: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id),
        );
    }

    public function test_limit_option_caps_orders_queued(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-LIMIT-1', null, null);
        $this->createOrder($agent, 'RD-LIMIT-2', null, null);
        $this->createOrder($agent, 'RD-LIMIT-3', null, null);

        $this->artisan('radiumbox:backfill-orders --limit=2')
            ->expectsOutputToContain('Orders queued: 2')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 2);
    }

    public function test_order_option_targets_single_order(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $target = $this->createOrder($agent, 'RD3433380', null, null);
        $this->createOrder($agent, 'RD-OTHER', null, null);

        $this->artisan('radiumbox:backfill-orders --order=RD3433380')
            ->expectsOutputToContain('Orders scanned: 1')
            ->expectsOutputToContain('Orders queued: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $target->id);
    }

    public function test_manual_device_model_assignment_is_not_queued_when_only_serial_missing(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $deviceModel = DeviceModel::query()->create([
            'name' => 'Manual Model',
            'code' => 'manual-model',
            'is_active' => true,
        ]);

        $this->createOrder($agent, 'RD-MANUAL-MODEL', null, 'Manual Model', $deviceModel->id);

        $this->artisan('radiumbox:backfill-orders --order=RD-MANUAL-MODEL')
            ->expectsOutputToContain('Orders queued: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
    }

    public function test_order_with_manual_serial_and_missing_device_model_is_skipped_when_device_model_assigned(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $deviceModel = DeviceModel::query()->create([
            'name' => 'Assigned Model',
            'code' => 'assigned-model',
            'is_active' => true,
        ]);

        $this->createOrder($agent, 'RD-COMPLETE', 'LOCAL-SERIAL', null, $deviceModel->id);

        $this->artisan('radiumbox:backfill-orders --order=RD-COMPLETE')
            ->expectsOutputToContain('Orders already complete: 1')
            ->expectsOutputToContain('Orders queued: 0')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function createOrder(
        User $agent,
        string $orderId,
        ?string $serialNumber,
        ?string $deviceModel,
        ?int $deviceModelId = null,
    ): Order {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber,
            'device_model' => $deviceModel,
            'device_model_id' => $deviceModelId,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    }
}
