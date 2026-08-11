<?php

namespace Tests\Unit\Timeline;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Timeline\Sources\ServiceCaseLifecycleTimelineEventSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseLifecycleTimelineEventSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_closed_status_change_emits_service_case_closed_type(): void
    {
        [$order, $incident, $closer] = $this->createFixture();

        AuditLog::query()->create([
            'user_id' => $closer->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => ['status' => IncidentStatus::Open->value],
            'new_values' => ['status' => IncidentStatus::Closed->value],
        ]);

        $events = app(ServiceCaseLifecycleTimelineEventSource::class, ['order' => $order])->collect();

        $this->assertCount(1, $events);
        $this->assertSame(TimelineEventType::ServiceCaseClosed, $events->first()->type);
        $this->assertSame('Incident closed', $events->first()->title);
    }

    public function test_non_closed_status_change_is_not_emitted(): void
    {
        [$order, $incident, $agent] = $this->createFixture();

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => ['status' => IncidentStatus::Open->value],
            'new_values' => ['status' => IncidentStatus::InProgress->value],
        ]);

        $events = app(ServiceCaseLifecycleTimelineEventSource::class, ['order' => $order])->collect();

        $this->assertCount(0, $events);
    }

    /**
     * @return array{0: Order, 1: Incident, 2: User}
     */
    private function createFixture(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-LC-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal->value,
            'title' => 'Lifecycle timeline fixture',
            'description' => 'Lifecycle timeline fixture.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $agent->id,
        ]);

        return [$order, $incident, $agent];
    }
}
