<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\ServiceCaseClosed;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Events\Dashboard\ServiceCaseResolved;
use App\Events\Dashboard\ServiceCasesAssigned;
use App\Events\Dashboard\ServiceCasesClosed;
use App\Events\Dashboard\ServiceCasesResolved;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HybridRealtimeAssignmentCloseResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_assignment_feature_off_skips_realtime_broadcasts(): void
    {
        Event::fake([ServiceCasesAssigned::class, ServiceCaseCreated::class, DashboardKpisUpdated::class]);

        [$admin, , $incident] = $this->createCase();

        app(DashboardBroadcastService::class)->serviceCaseAssigned($incident, $admin);

        Event::assertNotDispatched(ServiceCasesAssigned::class);
        Event::assertNotDispatched(ServiceCaseCreated::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_assignment_feature_on_dispatches_lightweight_event_without_html_or_kpis(): void
    {
        Event::fake([ServiceCasesAssigned::class, ServiceCaseCreated::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.assignment', true);

        [$admin, $viewer, $incident] = $this->createCase();

        $maxTransactionLevel = 0;

        Event::listen(ServiceCasesAssigned::class, function () use (&$maxTransactionLevel): void {
            $maxTransactionLevel = max($maxTransactionLevel, DB::transactionLevel());
        });

        app(DashboardBroadcastService::class)->serviceCaseAssigned($incident, $admin);

        $this->assertSame(0, $maxTransactionLevel);

        Event::assertDispatched(ServiceCasesAssigned::class, function (ServiceCasesAssigned $event) use ($viewer, $incident): bool {
            $payload = $event->broadcastWith();
            $first = $payload['incidents'][0] ?? null;

            return $event->recipient->is($viewer)
                && $payload['incident_ids'] === [$incident->id]
                && is_array($first)
                && $first['incident_id'] === $incident->id
                && isset($first['queue'], $first['status'], $first['updated_at'])
                && ! array_key_exists('html', $payload);
        });

        Event::assertNotDispatched(ServiceCaseCreated::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_assignment_coalesce_flushes_one_bulk_event_per_recipient(): void
    {
        Event::fake([ServiceCasesAssigned::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.assignment', true);

        [$admin, $viewer] = $this->createCase();
        $incidentIds = [];

        for ($index = 1; $index <= 3; $index++) {
            [, , $incident] = $this->createCase(admin: $admin, viewer: $viewer, suffix: "A{$index}");
            $incidentIds[] = $incident->id;
        }

        $broadcasts = app(DashboardBroadcastService::class);
        $broadcasts->beginAssignmentCoalesce($admin);

        foreach ($incidentIds as $incidentId) {
            $broadcasts->serviceCaseAssigned(
                Incident::query()->findOrFail($incidentId),
                $admin,
            );
        }

        $broadcasts->flushAssignmentCoalesce();

        $viewerDispatches = Event::dispatched(ServiceCasesAssigned::class)
            ->filter(function (array $args) use ($viewer): bool {
                $event = $args[0] ?? null;

                return $event instanceof ServiceCasesAssigned && $event->recipient->is($viewer);
            });

        $this->assertCount(1, $viewerDispatches);

        /** @var ServiceCasesAssigned $event */
        $event = $viewerDispatches->first()[0];
        $this->assertEqualsCanonicalizing($incidentIds, $event->broadcastWith()['incident_ids']);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_resolve_feature_off_skips_legacy_and_hybrid_broadcasts(): void
    {
        Event::fake([ServiceCasesResolved::class, ServiceCaseResolved::class, DashboardKpisUpdated::class]);

        [$admin, , $incident] = $this->createCase(status: IncidentStatus::Resolved);

        app(DashboardBroadcastService::class)->serviceCaseResolved($incident, $admin);

        Event::assertNotDispatched(ServiceCasesResolved::class);
        Event::assertNotDispatched(ServiceCaseResolved::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_resolve_feature_on_dispatches_lightweight_event(): void
    {
        Event::fake([ServiceCasesResolved::class, ServiceCaseResolved::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.close_resolve', true);

        [$admin, $viewer, $incident] = $this->createCase(status: IncidentStatus::Resolved);

        app(DashboardBroadcastService::class)->serviceCaseResolved($incident, $admin);

        Event::assertDispatched(ServiceCasesResolved::class, function (ServiceCasesResolved $event) use ($viewer, $incident): bool {
            $payload = $event->broadcastWith();

            return $event->recipient->is($viewer)
                && $payload['incident_ids'] === [$incident->id]
                && ($payload['incidents'][0]['status'] ?? null) === IncidentStatus::Resolved->value;
        });

        Event::assertNotDispatched(ServiceCaseResolved::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_close_feature_on_dispatches_lightweight_event_without_kpis(): void
    {
        Event::fake([ServiceCasesClosed::class, ServiceCaseClosed::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.close_resolve', true);

        [$admin, $viewer, $incident] = $this->createCase(status: IncidentStatus::Closed);

        app(DashboardBroadcastService::class)->serviceCaseClosed($incident, $admin);

        Event::assertDispatched(ServiceCasesClosed::class, function (ServiceCasesClosed $event) use ($viewer, $incident): bool {
            $payload = $event->broadcastWith();

            return $event->recipient->is($viewer)
                && $payload['incident_ids'] === [$incident->id]
                && ($payload['incidents'][0]['status'] ?? null) === IncidentStatus::Closed->value
                && ! array_key_exists('html', $payload);
        });

        Event::assertNotDispatched(ServiceCaseClosed::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_bulk_close_dispatches_one_event_per_recipient(): void
    {
        Event::fake([ServiceCasesClosed::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.close_resolve', true);

        [$admin, $viewer] = $this->createCase(status: IncidentStatus::Closed);
        $incidentIds = [];

        for ($index = 1; $index <= 3; $index++) {
            [, , $incident] = $this->createCase(
                admin: $admin,
                viewer: $viewer,
                suffix: "C{$index}",
                status: IncidentStatus::Closed,
            );
            $incidentIds[] = $incident->id;
        }

        app(DashboardBroadcastService::class)->serviceCasesClosed($incidentIds, $admin);

        $viewerDispatches = Event::dispatched(ServiceCasesClosed::class)
            ->filter(function (array $args) use ($viewer): bool {
                $event = $args[0] ?? null;

                return $event instanceof ServiceCasesClosed && $event->recipient->is($viewer);
            });

        $this->assertCount(1, $viewerDispatches);
        $this->assertEqualsCanonicalizing(
            $incidentIds,
            $viewerDispatches->first()[0]->broadcastWith()['incident_ids'],
        );
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    /**
     * @return array{0: User, 1: User, 2: Incident}
     */
    private function createCase(
        ?User $admin = null,
        ?User $viewer = null,
        string $suffix = '',
        IncidentStatus $status = IncidentStatus::InProgress,
    ): array {
        $admin ??= User::factory()->create(['is_active' => true]);
        if (! $admin->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $viewer ??= User::factory()->create(['is_active' => true]);
        if (! $viewer->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $suffix = $suffix !== '' ? $suffix : uniqid();

        $order = Order::query()->create([
            'order_id' => 'RD-P2-'.$suffix,
            'serial_number' => '7882'.substr(md5($suffix), 0, 4),
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-P2-'.$suffix,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Phase 2 hybrid case',
            'description' => 'Phase 2 hybrid case',
            'status' => $status,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        return [$admin, $viewer, $incident];
    }
}
