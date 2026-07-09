<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\ServiceCaseAutomationStatusService;
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

    public function test_dashboard_renders_enquiry_label_instead_of_inq_order_id(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC00099', assignTo: $agent);

        $response = $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Order / Enquiry')
            ->assertSee($incident->display_reference)
            ->assertSee('Enquiry')
            ->assertDontSee('INQ-SC00099');

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
            ->assertDontSee('INQ-SC00333');
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
