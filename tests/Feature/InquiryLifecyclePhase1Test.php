<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\Customer360Service;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\ServiceCaseAutomationStatusService;
use App\Support\Customer360\Customer360CommunicationActionStatusPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryLifecyclePhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_inquiry_missing_serial_is_not_waiting_customer(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = $this->createInquiryIncident($creator, 'SC00077');

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertFalse($classifier->isWaitingCustomer($freshIncident));
        $this->assertNotSame(
            OperationQueue::WaitingCustomer,
            $classifier->classify($freshIncident),
        );
    }

    public function test_rd_missing_serial_remains_waiting_customer(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-WAIT-SERIAL',
            'serial_number' => '',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Device missing serial',
            'description' => 'Waiting for serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertTrue($classifier->isWaitingCustomer($freshIncident));
        $this->assertSame(
            OperationQueue::WaitingCustomer,
            $classifier->classify($freshIncident),
        );
    }

    public function test_inquiry_missing_serial_does_not_enter_waiting_for_customer_serial_automation(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = $this->createInquiryIncident($creator, 'SC00088');

        $status = app(ServiceCaseAutomationStatusService::class)
            ->statusFor($incident->fresh(['order', 'assignee']));

        $this->assertNotSame(ServiceCaseAutomationStatus::WaitingForCustomerSerial, $status);
    }

    public function test_dashboard_renders_dash_for_inquiry_order_and_serial_columns(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC00099', assignTo: $agent);

        $response = $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>Order</th>', false)
            ->assertSee($incident->display_reference)
            ->assertDontSee('INQ-SC00099')
            ->assertDontSee('order-identifier--enquiry', false);

        $this->assertStringContainsString(
            'data-search-text="'.strtolower('inq-sc00099 '.$incident->display_reference),
            $response->getContent(),
        );
    }

    public function test_dashboard_still_renders_real_order_id_for_rd_cases(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Service request',
            'description' => 'Service request.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('RD3421021')
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_dashboard_search_still_finds_inquiry_by_internal_order_id(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC00123', assignTo: $agent);

        $matches = app(DashboardService::class)->incidentMatchesQuickSearch(
            $incident->fresh(['order']),
            'INQ-SC00123',
        );

        $this->assertTrue($matches);
    }

    public function test_inquiry_case_is_excluded_from_waiting_customer_queue_counts(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->createInquiryIncident($creator, 'SC00200');

        $order = Order::query()->create([
            'order_id' => 'RD-WAIT-COUNT-2',
            'serial_number' => '',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Device waiting serial',
            'description' => 'Device waiting serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $counts = DashboardSnapshot::load()->queueCounts();

        $this->assertSame(1, $counts['waiting_customer']);
        $this->assertGreaterThanOrEqual(1, $counts['action_required'] + $counts['attention']);
    }

    public function test_customer_360_shows_case_and_enquiry_type_for_inquiry(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC00333', assignTo: $agent);
        $incident->order->update(['customer_phone' => '9123456780']);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Case')
            ->assertSee($incident->display_reference)
            ->assertSee('Type')
            ->assertSee('Enquiry')
            ->assertDontSee('INQ-SC00333')
            ->assertDontSee('RD Service')
            ->assertDontSee('data-customer-360-radiumbox-sync', false);
    }

    public function test_product_order_retains_commercial_purchase_actions_in_menu_presenter_and_drawer(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110',
            'buy_rd_service_url' => 'https://radiumbox.com/rd-service/mfs-110',
            'buy_device_url' => 'https://radiumbox.com/shop/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD3421999',
            'serial_number' => '7881955',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'device_model_id' => $deviceModel->id,
            'customer_phone' => '9123456799',
            'customer_email' => 'product-buyer@example.com',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Product order purchase actions',
            'description' => 'Product order purchase actions.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $menuKeys = collect(
            app(CommunicationActionEligibilityService::class)
                ->menuItems($incident->fresh(['order.deviceModel']), $agent),
        )->pluck('key')->all();

        $this->assertContains('buy_rd_service', $menuKeys);
        $this->assertContains('buy_product', $menuKeys);

        $statusKeys = collect(
            app(Customer360CommunicationActionStatusPresenter::class)
                ->forIncident($incident->fresh(['order.deviceModel']), $agent),
        )->pluck('key')->all();

        $this->assertContains('buy_rd_service', $statusKeys);
        $this->assertContains('buy_product', $statusKeys);

        $drawerData = app(Customer360Service::class)->drawerData($incident->fresh(['order.deviceModel']));
        $this->assertSame('MFS 110', $drawerData['device']['product_name'] ?? null);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-workspace-communication-action-key="buy_rd_service"', false)
            ->assertSee('data-workspace-communication-action-key="buy_product"', false);
    }

    public function test_inquiry_omits_commercial_purchase_actions_from_eligibility_menu_and_drawer_payload(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC00335', assignTo: $agent);
        $incident->order->update(['customer_phone' => '9123456781']);

        $menuKeys = collect(
            app(CommunicationActionEligibilityService::class)
                ->menuItems($incident->fresh(['order']), $agent),
        )->pluck('key')->all();

        $this->assertNotContains('buy_rd_service', $menuKeys);
        $this->assertNotContains('buy_product', $menuKeys);

        $statusKeys = collect(
            app(Customer360CommunicationActionStatusPresenter::class)
                ->forIncident($incident->fresh(['order']), $agent),
        )->pluck('key')->all();

        $this->assertNotContains('buy_rd_service', $statusKeys);
        $this->assertNotContains('buy_product', $statusKeys);

        $drawerData = app(Customer360Service::class)->drawerData($incident->fresh(['order']));
        $this->assertNull($drawerData['device']['product_name'] ?? null);
    }

    public function test_customer_360_inquiry_active_services_show_enquiry_only(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC00334', assignTo: $agent);

        $data = app(Customer360Service::class)->drawerData($incident->fresh(['order']));

        $this->assertFalse($data['device']['can_manual_sync']);
        $this->assertFalse($data['device']['show_sync_diagnostics']);
        $this->assertFalse($data['device']['should_poll_sync']);
        $this->assertSame(
            [['label' => 'Enquiry', 'status' => 'Open', 'variant' => 'info']],
            $data['activeServices'],
        );
    }

    private function createInquiryIncident(
        User $creator,
        string $referenceNo,
        ?User $assignTo = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference($referenceNo),
            'serial_number' => '',
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General Enquiry',
            'source' => IncidentSource::Call,
            'title' => 'New contact enquiry',
            'description' => 'New contact enquiry.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignTo?->id,
        ]);
    }
}
