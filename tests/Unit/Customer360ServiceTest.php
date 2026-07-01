<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
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
        $this->assertNull($data['healthCard']['last_email']);

        $this->assertInstanceOf(\App\Data\TimelineViewModel::class, $data['timeline']);
        $this->assertLessThanOrEqual(8, $data['timeline']->events()->count());
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
}
