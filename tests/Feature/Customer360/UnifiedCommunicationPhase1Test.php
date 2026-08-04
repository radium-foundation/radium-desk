<?php

namespace Tests\Feature\Customer360;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedCommunicationPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_360_includes_communication_section_with_whatsapp_and_email(): void
    {
        $agent = User::factory()->create([
            'first_name' => 'Asha',
            'last_name' => 'Owner',
            'name' => 'Asha Owner',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-UC-001',
            'serial_number' => 'SN-UC-001',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-UC-001',
            'customer_name' => 'Unified Customer',
            'customer_email' => 'unified@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Unified communication case',
            'description' => 'Phase 1 communication section.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('data-c360-communication-section', false);
        $response->assertSee('data-c360-channel-card="whatsapp"', false);
        $response->assertSee('data-c360-channel-card="email"', false);
        $response->assertSee('data-c360-whatsapp-open', false);
        $response->assertSee('data-c360-email-open', false);
        $response->assertSee('https://wa.me/919876543210', false);
        $response->assertSee('data-service-case-whatsapp-panel', false);
        $response->assertSee('data-sc-email-meta-customer', false);
        $response->assertSee('data-sc-email-meta-owner', false);
        $response->assertSee('Customer', false);
        $response->assertSee('Owner', false);
        $response->assertSee('Last inbound', false);
        $response->assertSee('Last outbound', false);
        $response->assertSee('Calls', false);
        $response->assertSee('SMS', false);
        $response->assertSee('AI Notes', false);
    }

    public function test_whatsapp_channel_disabled_without_phone(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-UC-002',
            'serial_number' => 'SN-UC-002',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-UC-002',
            'customer_name' => 'No Phone Customer',
            'customer_email' => 'nophone@example.com',
            'customer_phone' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'No phone case',
            'description' => 'WhatsApp unavailable.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('No phone number on this Service Case.', false);
        $response->assertSee('data-c360-channel-card="email"', false);
    }
}
