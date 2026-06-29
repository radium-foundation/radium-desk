<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\DeviceModel;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillRadiumBoxOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $nextCashfreePaymentId = 5_898_147_860;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
        ]);
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
        $this->createOrder($agent, 'RD-BACKFILL-3', 'SERIAL-2', null);

        $this->artisan('radiumbox:backfill-orders --dry-run')
            ->expectsOutputToContain('Orders scanned: 2')
            ->expectsOutputToContain('Orders would queue: 2')
            ->expectsOutputToContain('Orders already complete: 0')
            ->expectsOutputToContain('Immediate API calls: 0')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'RadiumBox order backfill completed.'
                    && ($context['dry_run'] ?? null) === true
                    && ($context['orders_would_queue'] ?? null) === 2
                    && ($context['orders_already_complete'] ?? null) === 0;
            });
    }

    public function test_command_dispatches_existing_enrichment_job_for_qualifying_orders(): void
    {
        Log::spy();
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

        Log::shouldHaveReceived('info')
            ->with('RadiumBox enrichment retry started.', \Mockery::on(function (array $context) use ($order): bool {
                return ($context['order_id'] ?? null) === $order->order_id
                    && ($context['dry_run'] ?? null) === false;
            }));
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
        Log::spy();
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

        Log::shouldHaveReceived('info')
            ->with('RadiumBox enrichment retry skipped.', \Mockery::on(function (array $context): bool {
                return ($context['order_id'] ?? null) === 'RD-COMPLETE'
                    && ($context['reason'] ?? null) === 'already_complete';
            }));
    }

    public function test_non_cashfree_orders_are_skipped_when_targeted_directly(): void
    {
        Log::spy();
        Queue::fake();

        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-NO-CF', null, null, cashfreePaymentId: null);

        $this->artisan('radiumbox:backfill-orders --order=RD-NO-CF')
            ->expectsOutputToContain('Orders scanned: 1')
            ->expectsOutputToContain('Orders queued: 0')
            ->expectsOutputToContain('Orders skipped: 1')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->with('RadiumBox enrichment retry skipped.', \Mockery::on(function (array $context): bool {
                return ($context['order_id'] ?? null) === 'RD-NO-CF'
                    && ($context['reason'] ?? null) === 'not_cashfree_paid_order';
            }));
    }

    public function test_eligible_paid_order_is_updated_when_job_runs(): void
    {
        Log::spy();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '9655721',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD3434460', null, null);

        Queue::fake();
        $this->artisan('radiumbox:backfill-orders --order=RD3434460')->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);

        (new RadiumBoxOrderEnrichmentJob($order->id))
            ->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );

        $order->refresh();

        $this->assertSame('9655721', $order->serial_number);
        $this->assertSame('MFS 110', $order->device_model);

        Log::shouldHaveReceived('info')
            ->with('RadiumBox enrichment retry succeeded.', \Mockery::on(function (array $context): bool {
                return ($context['order_id'] ?? null) === 'RD3434460'
                    && in_array('serial_number', $context['fields_applied'] ?? [], true);
            }));
    }

    public function test_order_still_missing_serial_remains_eligible_after_no_data_sync(): void
    {
        Log::spy();
        Queue::fake();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD3434461', null, null);

        $this->artisan('radiumbox:backfill-orders --order=RD3434461')->assertSuccessful();
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);

        (new RadiumBoxOrderEnrichmentJob($order->id))
            ->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );

        $order->refresh();
        $this->assertNull($order->serial_number);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Synced,
            app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id),
        );

        Queue::fake();

        $this->artisan('radiumbox:backfill-orders --order=RD3434461')->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
    }

    public function test_duplicate_serial_protection_still_applies_during_backfill_retry(): void
    {
        Log::spy();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS110',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();

        $this->createOrder($agent, 'RD3432834', '7881953', 'MFS 110', cashfreePaymentId: '5899000001');
        $incoming = $this->createOrder($agent, 'RD3434846', null, null);

        Queue::fake();
        $this->artisan('radiumbox:backfill-orders --order=RD3434846')->assertSuccessful();

        (new RadiumBoxOrderEnrichmentJob($incoming->id))
            ->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );

        $incoming->refresh();

        $this->assertNull($incoming->serial_number);
        $this->assertSame('MFS110', $incoming->device_model);

        Log::shouldHaveReceived('warning')
            ->with('Duplicate serial prevented.', \Mockery::type('array'));

        Log::shouldHaveReceived('info')
            ->with('RadiumBox enrichment retry succeeded.', \Mockery::on(function (array $context): bool {
                return ($context['order_id'] ?? null) === 'RD3434846'
                    && ! in_array('serial_number', $context['fields_applied'] ?? [], true)
                    && in_array('device_model', $context['fields_applied'] ?? [], true);
            }));
    }

    public function test_command_skips_orders_with_pending_enrichment_to_avoid_queue_storms(): void
    {
        Log::spy();
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD-PENDING', null, null);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($order->id);

        $this->artisan('radiumbox:backfill-orders --order=RD-PENDING')
            ->expectsOutputToContain('Orders skipped: 1')
            ->expectsOutputToContain('Orders queued: 0')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->with('RadiumBox enrichment retry skipped.', \Mockery::on(function (array $context): bool {
                return ($context['order_id'] ?? null) === 'RD-PENDING'
                    && ($context['reason'] ?? null) === 'enrichment_already_pending';
            }));
    }

    public function test_command_can_be_run_multiple_times_safely_after_synced_no_data(): void
    {
        Log::spy();
        Queue::fake();

        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD-REPEAT', null, null);
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markSynced($order->id, ['lookup_result' => 'no_data']);

        $this->artisan('radiumbox:backfill-orders --order=RD-REPEAT')
            ->expectsOutputToContain('Orders queued: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);

        Queue::fake();
        $syncStore->markPending($order->id);

        $this->artisan('radiumbox:backfill-orders --order=RD-REPEAT')
            ->expectsOutputToContain('Orders skipped: 1')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function createOrder(
        User $agent,
        string $orderId,
        ?string $serialNumber,
        ?string $deviceModel,
        ?int $deviceModelId = null,
        string|null|false $cashfreePaymentId = false,
    ): Order {
        if ($cashfreePaymentId === false) {
            $cashfreePaymentId = (string) ($this->nextCashfreePaymentId++);
        }

        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber,
            'device_model' => $deviceModel,
            'device_model_id' => $deviceModelId,
            'cashfree_payment_id' => $cashfreePaymentId,
            'payment_date' => $cashfreePaymentId !== null ? now() : null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    }
}
