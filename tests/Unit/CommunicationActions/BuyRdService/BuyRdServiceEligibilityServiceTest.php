<?php

namespace Tests\Unit\CommunicationActions\BuyRdService;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\BuyRdService\BuyRdServiceEligibilityService;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyRdServiceEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_is_eligible_when_device_model_has_rd_service_url_and_case_is_resolved(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Resolved, withCatalogUrls: true);

        $this->assertNull(app(BuyRdServiceEligibilityService::class)->ineligibilityReason($incident));
        $this->assertTrue(app(CommunicationActionEligibilityService::class)->canShowAction(
            app(CommunicationActionRegistry::class)->get(CommunicationActionKey::BuyRdService),
            $incident,
            $agent,
        ));
    }

    public function test_is_eligible_when_case_is_operationally_active(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Open, withCatalogUrls: true);

        $this->assertNull(app(BuyRdServiceEligibilityService::class)->ineligibilityReason($incident));
    }

    public function test_is_ineligible_without_device_model(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Resolved, withCatalogUrls: false);

        $reason = app(BuyRdServiceEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Assign a device model before sending RD Service purchase information.',
            $reason,
        );
    }

    public function test_is_ineligible_without_rd_service_url(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Resolved, withCatalogUrls: true, rdServiceUrl: null);

        $reason = app(BuyRdServiceEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'No RD Service purchase link is available for this device model.',
            $reason,
        );
    }

    public function test_is_ineligible_without_customer_contact(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident(
            actor: $agent,
            status: IncidentStatus::Resolved,
            withCatalogUrls: true,
            customerPhone: '',
            customerEmail: '',
        );

        $reason = app(BuyRdServiceEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Customer contact details are required before sending RD Service purchase information.',
            $reason,
        );
    }

    public function test_is_ineligible_when_case_is_closed(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Closed, withCatalogUrls: true);

        $reason = app(CommunicationActionEligibilityService::class)->ineligibilityReason(
            app(CommunicationActionRegistry::class)->get(CommunicationActionKey::BuyRdService),
            $incident,
            $agent,
        );

        $this->assertSame('Communication actions are unavailable on closed service cases.', $reason);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        IncidentStatus $status,
        bool $withCatalogUrls = false,
        ?string $rdServiceUrl = 'https://radiumbox.com/rd-service/mfs-110',
        string $customerPhone = '9876543210',
        string $customerEmail = 'customer@example.com',
    ): array {
        $deviceModel = null;

        if ($withCatalogUrls) {
            $deviceModel = DeviceModel::query()->create([
                'name' => 'MFS 110',
                'buy_rd_service_url' => $rdServiceUrl,
                'buy_device_url' => 'https://radiumbox.com/shop/mfs-110',
                'display_order' => 1,
                'is_active' => true,
            ]);
        }

        $order = Order::query()->create([
            'order_id' => 'RD-BUY-RD-ELIG',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'device_model_id' => $deviceModel?->id,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_name' => 'Catalog Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Buy RD Service eligibility case',
            'description' => 'Buy RD Service eligibility case.',
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }
}
