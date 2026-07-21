<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\ReferenceNumbersUpdated;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Events\Dashboard\TransactionAssigned;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\OrderTransactionService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HybridRealtimeReferenceNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_feature_off_skips_reference_number_and_legacy_transaction_broadcasts(): void
    {
        Event::fake([ReferenceNumbersUpdated::class, TransactionAssigned::class, DashboardKpisUpdated::class]);

        [$admin, $viewer, $order] = $this->createAssignableCase();

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-HYBRID-OFF',
            actor: $admin,
        );

        Event::assertNotDispatched(ReferenceNumbersUpdated::class);
        Event::assertNotDispatched(TransactionAssigned::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);

        $this->assertSame('TXN-HYBRID-OFF', $order->fresh()->transaction_id);
        $this->assertTrue($viewer->is_active);
    }

    public function test_feature_on_single_dispatches_lightweight_event_without_kpis_or_html(): void
    {
        Event::fake([ReferenceNumbersUpdated::class, TransactionAssigned::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.reference_number', true);

        [$admin, $viewer, $order, $incident] = $this->createAssignableCase();

        $maxTransactionLevel = 0;

        Event::listen(ReferenceNumbersUpdated::class, function () use (&$maxTransactionLevel): void {
            $maxTransactionLevel = max($maxTransactionLevel, DB::transactionLevel());
        });

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-HYBRID-ON',
            actor: $admin,
        );

        $this->assertSame(0, $maxTransactionLevel);

        Event::assertDispatched(ReferenceNumbersUpdated::class, function (ReferenceNumbersUpdated $event) use ($viewer, $incident): bool {
            $payload = $event->broadcastWith();

            return $event->recipient->is($viewer)
                && $payload['incident_ids'] === [$incident->id]
                && isset($payload['updated_at'])
                && ! array_key_exists('html', $payload);
        });

        Event::assertNotDispatched(TransactionAssigned::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_feature_on_bulk_dispatches_one_event_per_recipient(): void
    {
        Event::fake([ReferenceNumbersUpdated::class, TransactionAssigned::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.reference_number', true);

        [$admin, $viewer] = $this->createAssignableCase();

        $incidentIds = [];

        for ($index = 1; $index <= 3; $index++) {
            [, , $order, $incident] = $this->createAssignableCase(
                admin: $admin,
                viewer: $viewer,
                orderSuffix: "BULK-{$index}",
            );
            $incidentIds[] = $incident->id;

            app(OrderTransactionService::class)->assignTransactionId(
                order: $order,
                transactionId: "TXN-BULK-{$index}",
                actor: $admin,
                broadcast: false,
            );
        }

        app(DashboardBroadcastService::class)->transactionsAssigned($incidentIds, $admin);

        $viewerDispatches = Event::dispatched(ReferenceNumbersUpdated::class)
            ->filter(function (array $args) use ($viewer): bool {
                $event = $args[0] ?? null;

                return $event instanceof ReferenceNumbersUpdated && $event->recipient->is($viewer);
            });

        $this->assertCount(1, $viewerDispatches);

        /** @var ReferenceNumbersUpdated $event */
        $event = $viewerDispatches->first()[0];
        $payload = $event->broadcastWith();

        $this->assertEqualsCanonicalizing($incidentIds, $payload['incident_ids']);
        Event::assertNotDispatched(TransactionAssigned::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_queue_membership_broadcasts_remain_unaffected_when_reference_number_feature_off(): void
    {
        Event::fake([ServiceCaseCreated::class, ReferenceNumbersUpdated::class]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-QUEUE-UNAFFECTED',
            'serial_number' => '7881999',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-QUEUE-UNAFFECTED',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Queue membership unaffected',
            'description' => 'Queue membership unaffected',
            'status' => IncidentStatus::Open,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        app(DashboardBroadcastService::class)->serviceCaseQueueMembershipChanged($incident, $admin);

        Event::assertDispatched(ServiceCaseCreated::class, function (ServiceCaseCreated $event) use ($viewer, $incident): bool {
            return $event->recipient->is($viewer) && $event->incident->is($incident);
        });
        Event::assertNotDispatched(ReferenceNumbersUpdated::class);
    }

    public function test_live_rows_endpoint_returns_fragments_for_visible_incidents(): void
    {
        [$admin, $viewer, $order, $incident] = $this->createAssignableCase();

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-ROWS',
            actor: $admin,
        );

        $this->actingAs($viewer)
            ->getJson(route('dashboard.live.rows', [
                'ids' => [$incident->id],
                'queue' => 'completed',
            ]))
            ->assertOk()
            ->assertJsonStructure([
                'rows',
                'remove_incident_ids',
            ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Order, 3: Incident}
     */
    private function createAssignableCase(
        ?User $admin = null,
        ?User $viewer = null,
        string $orderSuffix = '',
    ): array {
        $admin ??= User::factory()->create(['is_active' => true]);
        if (! $admin->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $viewer ??= User::factory()->create(['is_active' => true]);
        if (! $viewer->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $suffix = $orderSuffix !== '' ? $orderSuffix : uniqid();

        $order = Order::query()->create([
            'order_id' => 'RD-HYBRID-'.$suffix,
            'serial_number' => '7881'.substr(md5($suffix), 0, 4),
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-HYBRID-'.$suffix,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hybrid realtime case',
            'description' => 'Hybrid realtime case',
            'status' => IncidentStatus::InProgress,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        return [$admin, $viewer, $order->fresh(), $incident];
    }
}
