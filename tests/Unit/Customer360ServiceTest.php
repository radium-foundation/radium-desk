<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Customer360\Customer360DrawerProfiler;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360ServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_drawer_data_includes_customer_device_and_summary_counts(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $sharedPhone = '9876543210';

        $orderOne = Order::query()->create([
            'order_id' => 'RD-360-ONE',
            'serial_number' => 'SN-360-ONE',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-360',
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $orderTwo = Order::query()->create([
            'order_id' => 'RD-360-TWO',
            'serial_number' => 'SN-360-TWO',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Customer',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $openIncident = Incident::query()->create([
            'order_id' => $orderOne->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open case',
            'description' => 'Open case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $orderTwo->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed case',
            'description' => 'Closed case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($orderOne->id, [
            'warranty' => 'Active',
            'amc' => 'Expired',
        ]);

        $data = app(Customer360Service::class)->drawerData($openIncident);

        $this->assertSame('Jane Customer', $data['customer']['name']);
        $this->assertSame($sharedPhone, $data['customer']['mobile']);
        $this->assertSame('jane@example.com', $data['customer']['email']);
        $this->assertNull($data['customer']['city']);

        $this->assertSame('MFS E3', $data['device']['model_short']);
        $this->assertSame('MFS 110 E3', $data['device']['model_canonical']);
        $this->assertSame('SN-360-ONE', $data['device']['serial_number']);
        $this->assertSame('RD-360-ONE', $data['device']['order_id']);
        $this->assertSame('TXN-360', $data['device']['service_reference']);

        $this->assertSame('Active', collect($data['activeServices'])->firstWhere('label', 'RD Service')['status']);
        $this->assertSame('Active', collect($data['activeServices'])->firstWhere('label', 'Warranty')['status']);
        $this->assertSame('Expired', collect($data['activeServices'])->firstWhere('label', 'AMC')['status']);

        $this->assertSame(2, $data['summary']['total_orders']);
        $this->assertSame(2, $data['summary']['total_devices']);
        $this->assertSame(1, $data['summary']['open_cases']);
        $this->assertSame(1, $data['summary']['closed_cases']);

        $this->assertSame('Jane Customer', $data['healthCard']['name']);
        $this->assertSame($sharedPhone, $data['healthCard']['phone']);
        $this->assertSame('jane@example.com', $data['healthCard']['email']);
        $this->assertSame('Active', $data['healthCard']['warranty_status']);
        $this->assertSame(1, $data['healthCard']['active_service_cases']);
        $this->assertNull($data['healthCard']['last_call']);
        $this->assertSame('not_sent', $data['healthCard']['last_whatsapp']['status']);
        $this->assertSame('not_sent', $data['healthCard']['last_email']['status']);
        $this->assertFalse($data['serialRequestState']['requested']);
        $this->assertNull($data['serialRequestState']['requested_at']);

        $this->assertArrayHasKey('timelineTabUrl', $data);
        $this->assertArrayHasKey('aiTabUrl', $data);
        $this->assertArrayHasKey('executiveSummaryUrl', $data);
        $this->assertArrayNotHasKey('timeline', $data);
        $this->assertArrayNotHasKey('aiAssistant', $data);
        $this->assertArrayNotHasKey('executiveSummary', $data);
    }

    public function test_drawer_data_initial_payload_completes_under_performance_budget(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-PERF',
            'serial_number' => 'SN-360-PERF',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'customer_name' => 'Perf Customer',
            'customer_phone' => '9876500000',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Perf case',
            'description' => 'Perf case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $profiler = new Customer360DrawerProfiler;
        $service = app(Customer360Service::class);

        $data = $profiler->measure('drawer_data', fn () => $service->drawerData($incident));
        $html = $profiler->measure('render', fn () => view('customer-360.drawer-content', $data)->render());

        $this->assertLessThan(500, $profiler->totalMs(), 'Initial Customer 360 payload should open under 500ms.');
        $this->assertStringContainsString('data-customer-360-section="health-card"', $html);
        $this->assertStringContainsString('data-customer-360-timeline-tab', $html);
        $this->assertStringContainsString('data-customer-360-ai-tab', $html);
    }

    public function test_timeline_tab_payload_includes_timeline_operations_health_and_sla_metrics(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-TAB',
            'serial_number' => 'SN-360-TAB',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876501111',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Timeline tab case',
            'description' => 'Timeline tab case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $payload = app(Customer360Service::class)->timelineTabPayload($incident);

        $this->assertInstanceOf(\App\Data\TimelineViewModel::class, $payload['timeline']);
        $this->assertIsArray($payload['operationsHealth']);
        $this->assertNotNull($payload['slaMetrics']);
        $this->assertStringContainsString('Customer Timeline', $payload['html']);
        $this->assertStringContainsString('Operations Health', $payload['html']);
        $this->assertStringContainsString('SLA Metrics', $payload['html']);
    }

    public function test_active_services_show_not_available_when_enrichment_missing(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-EMPTY',
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
            'title' => 'Case',
            'description' => 'Case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $data = app(Customer360Service::class)->drawerData($incident);

        $this->assertSame('Pending', collect($data['activeServices'])->firstWhere('label', 'RD Service')['status']);
        $this->assertSame('Not Available', collect($data['activeServices'])->firstWhere('label', 'Warranty')['status']);
        $this->assertSame('Not Available', collect($data['activeServices'])->firstWhere('label', 'AMC')['status']);
    }

    public function test_active_service_chip_shows_scheduled_when_appointment_exists(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-SCHED',
            'serial_number' => 'SN-360-SCHED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-360-SCHED',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scheduled case',
            'description' => 'Scheduled case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876502222',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $data = app(Customer360Service::class)->drawerData($incident->fresh());

        $this->assertSame('Scheduled', collect($data['activeServices'])->firstWhere('label', 'RD Service')['status']);
        $this->assertSame('Not Available', collect($data['activeServices'])->firstWhere('label', 'Warranty')['status']);
        $this->assertSame('Not Available', collect($data['activeServices'])->firstWhere('label', 'AMC')['status']);
    }
}
