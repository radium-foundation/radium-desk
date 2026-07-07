<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360WhatsAppFlowStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_360_shows_whatsapp_flow_not_configured_without_appointment(): void
    {
        [$agent, $incident] = $this->createFixture();

        $timelineHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('WhatsApp Flow', $timelineHtml);
        $this->assertStringContainsString('Not Configured', $timelineHtml);
    }

    public function test_customer_360_shows_whatsapp_flow_ready_when_appointment_exists(): void
    {
        [$agent, $incident, $order] = $this->createFixture();

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => $order->customer_phone,
        ]);

        $timelineHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('WhatsApp Flow', $timelineHtml);
        $this->assertStringContainsString('Ready', $timelineHtml);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createFixture(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-FLOW-360',
            'serial_number' => 'SN-FLOW-360',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Flow Status Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Flow status case',
            'description' => 'Flow status case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident, $order];
    }
}
