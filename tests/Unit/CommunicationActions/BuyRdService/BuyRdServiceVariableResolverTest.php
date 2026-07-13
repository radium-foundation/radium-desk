<?php

namespace Tests\Unit\CommunicationActions\BuyRdService;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionVariableResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyRdServiceVariableResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'communication_actions.company_name' => 'Radium Box',
            'communication_actions.support_contact' => 'support@radiumbox.com',
        ]);
    }

    public function test_resolves_buy_rd_service_variables_from_device_model_catalog(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110',
            'buy_rd_service_url' => 'https://radiumbox.com/rd-service/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-BUY-RD-VARS',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'device_model_id' => $deviceModel->id,
            'customer_name' => 'Jane Catalog',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Buy RD Service variable case',
            'description' => 'Buy RD Service variable case.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $definition = app(CommunicationActionRegistry::class)->get(CommunicationActionKey::BuyRdService);

        $variables = app(CommunicationActionVariableResolver::class)->resolve(
            definition: $definition,
            incident: $incident,
            operator: $agent,
        );

        $this->assertSame('Jane Catalog', $variables['customer_name']);
        $this->assertSame('Radium Box', $variables['company_name']);
        $this->assertSame('https://radiumbox.com/rd-service/mfs-110', $variables['buy_rd_service_url']);
        $this->assertSame('support@radiumbox.com', $variables['support_contact']);
        $this->assertSame([
            'Jane Catalog',
            'https://radiumbox.com/rd-service/mfs-110',
        ], $variables['whatsapp_body_values']);
    }
}
