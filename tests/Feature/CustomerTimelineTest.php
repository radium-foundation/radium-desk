<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\Timeline\Sources\OrderCustomerTimelineSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_order_customer_timeline_source_maps_supported_event_types(): void
    {
        $agent = User::factory()->create(['first_name' => 'Priya']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TL-1',
            'serial_number' => 'SN-TL-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-TL-1',
            'customer_name' => 'Timeline Customer',
            'customer_phone' => '9000000001',
            'payment_amount' => 499.00,
            'payment_method' => 'UPI',
            'payment_date' => now()->subHour(),
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Timeline case',
            'description' => 'Timeline case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => [],
            'new_values' => ['assigned_to_user_id' => $agent->id],
        ]);

        $longNote = str_repeat('Customer requested callback. ', 12);

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => $longNote,
        ]);

        $events = app(OrderCustomerTimelineSource::class, ['order' => $order->fresh()])->collect();
        $types = $events->map(fn ($event) => $event->type)->unique()->values();

        $this->assertTrue($types->contains(TimelineEventType::Payment));
        $this->assertTrue($types->contains(TimelineEventType::ServiceCaseCreated));
        $this->assertTrue($types->contains(TimelineEventType::Assignment));
        $this->assertTrue($types->contains(TimelineEventType::InternalNote));

        $note = $events->first(fn ($event) => $event->type === TimelineEventType::InternalNote);
        $this->assertTrue($note->isDetailExpandable());
        $this->assertSame(TimelineEvent::INTERNAL_NOTE_TITLE, $note->title);
        $this->assertSame(trim($longNote), $note->noteBody);
    }

    public function test_customer_360_timeline_endpoint_returns_older_events_fragment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TL-PAGE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Paged case',
            'description' => 'Paged case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        foreach (range(1, 10) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'order.updated',
                'auditable_type' => $order->getMorphClass(),
                'auditable_id' => $order->id,
                'old_values' => ['customer_name' => "Old {$index}"],
                'new_values' => [
                    'customer_name' => "New {$index}",
                    'correction_reason' => 'Test correction',
                ],
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $viewModel = app(Customer360TimelineService::class)->forIncident($incident, offset: 8);

        $this->assertGreaterThan(0, $viewModel->events()->count());
        $this->assertFalse($viewModel->hasMore);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360.timeline', [
            'incident' => $incident,
            'offset' => 8,
        ]));

        $response->assertOk();
        $response->assertSee('data-timeline-event', false);
        $response->assertDontSee('Load older events', false);
    }

    public function test_customer_360_drawer_renders_unified_timeline_markup(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TL-UI',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'UI case',
            'description' => 'UI case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => [],
            'new_values' => [],
        ]);

        $timelineHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Activity', $timelineHtml);
        $this->assertStringContainsString('data-unified-timeline', $timelineHtml);
        $this->assertStringContainsString('Payment received', $timelineHtml);
    }
}
