<?php

namespace Tests\Unit\CommunicationActions\DriverInstallationGuide;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionVariableResolver;
use App\Services\CommunicationActions\DriverInstallationGuide\DriverInstallationGuideEligibilityService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverInstallationGuideEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_is_eligible_when_incident_is_active_contact_exists_and_driver_link_is_available(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(DriverInstallationGuideEligibilityService::class);

        $this->assertNull($service->ineligibilityReason($incident));
        $this->assertTrue(app(CommunicationActionEligibilityService::class)->canShowAction(
            app(CommunicationActionRegistry::class)->get(CommunicationActionKey::DriverInstallationGuide),
            $incident,
            $agent,
        ));
    }

    public function test_is_ineligible_without_customer_contact(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, customerPhone: '', customerEmail: '');

        $reason = app(DriverInstallationGuideEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Customer contact details are required before sending the driver installation guide.',
            $reason,
        );
    }

    public function test_is_ineligible_without_driver_link_mapping(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'Unmapped Model',
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$incident] = $this->createIncident($agent, deviceModel: $deviceModel);

        $reason = app(DriverInstallationGuideEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'No driver download link is available for this device model.',
            $reason,
        );
    }

    public function test_variable_resolver_resolves_driver_installation_guide_fields(): void
    {
        $agent = User::factory()->create(['name' => 'Agent Smith']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);
        $definition = app(CommunicationActionRegistry::class)->get(CommunicationActionKey::DriverInstallationGuide);

        $variables = app(CommunicationActionVariableResolver::class)->resolve(
            definition: $definition,
            incident: $incident,
            operator: $agent,
        );

        $this->assertSame('Test Customer', $variables['customer_name']);
        $this->assertSame('Agent Smith', $variables['agent_name']);
        $this->assertSame('MFS 110', $variables['model_name']);
        $this->assertSame('https://radiumbox.com/drivers/mfs-110', $variables['driver_download_link']);
        $this->assertSame('support@radiumbox.com', $variables['support_contact']);
        $this->assertSame('Radium Box', $variables['company_name']);
        $this->assertNotSame('', $variables['case_number']);
        $this->assertSame([
            'Test Customer',
            'https://radiumbox.com/drivers/mfs-110',
        ], $variables['whatsapp_body_values']);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        ?DeviceModel $deviceModel = null,
        string $customerPhone = '9876543210',
        string $customerEmail = 'customer@example.com',
    ): array {
        $deviceModel ??= DeviceModel::query()->create([
            'name' => 'MFS 110',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-DRIVER-GUIDE',
            'serial_number' => 'SN-DRIVER',
            'product_name' => $deviceModel->name,
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Driver installation guide case',
            'description' => 'Driver installation guide case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }
}
