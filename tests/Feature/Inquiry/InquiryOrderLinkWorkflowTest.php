<?php

namespace Tests\Feature\Inquiry;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NewContactIntent;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Inquiry\InquiryOrderLinkService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\ServiceCaseAutomationStatusService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InquiryOrderLinkWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_inquiry_case_links_to_rd_order_without_changing_reference(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08850', phone: '9876543210');
        $rdOrder = $this->createRdOrder('RD3446000', phone: '9876543210');
        $inquiryOrderId = $incident->order_id;

        $linked = app(InquiryOrderLinkService::class)->linkToOrder($incident, $rdOrder, $admin);

        $this->assertSame('SC08850', $linked->reference_no);
        $this->assertSame($rdOrder->id, $linked->order_id);
        $this->assertSame($inquiryOrderId, $linked->inquiry_origin_order_id);
        $this->assertSame('INQ-SC08850', Order::query()->find($linked->inquiry_origin_order_id)?->order_id);
        $this->assertDatabaseHas('orders', [
            'id' => $linked->inquiry_origin_order_id,
            'order_id' => 'INQ-SC08850',
        ]);
    }

    public function test_link_creates_audit_event(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08851', phone: '9876543211');
        $rdOrder = $this->createRdOrder('RD3446001', phone: '9876543211');

        app(InquiryOrderLinkService::class)->linkToOrder($incident, $rdOrder, $admin);

        $this->assertDatabaseHas('audit_logs', [
            'event' => InquiryOrderLinkService::AUDIT_EVENT,
            'auditable_type' => (new Incident)->getMorphClass(),
            'auditable_id' => $incident->id,
            'user_id' => $admin->id,
        ]);

        $audit = AuditLog::query()
            ->where('event', InquiryOrderLinkService::AUDIT_EVENT)
            ->first();

        $this->assertSame('INQ-SC08851', $audit?->old_values['inquiry_order_id'] ?? null);
        $this->assertSame('RD3446001', $audit?->new_values['rd_order_id'] ?? null);
        $this->assertSame('SC08851', $audit?->new_values['reference_no'] ?? null);
    }

    public function test_link_reruns_assignment_eligibility_for_rd_rules(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08852', phone: '9876543212');
        $rdOrder = $this->createRdOrder('RD3446002', phone: '9876543212', serial: '');

        app(InquiryOrderLinkService::class)->linkToOrder($incident, $rdOrder, $admin);

        $freshIncident = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
        $status = app(ServiceCaseAutomationStatusService::class)->statusFor($freshIncident);

        $this->assertFalse($freshIncident->order?->isInquiryOrder() ?? true);
        $this->assertSame(ServiceCaseAutomationStatus::WaitingForCustomerSerial, $status);
    }

    public function test_link_unlocks_serial_and_service_workflows(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08853', phone: '9876543213');
        $rdOrder = $this->createRdOrder('RD3446003', phone: '9876543213', serial: '');

        $eligibility = app(RequestSerialNumberEligibilityService::class);
        $this->assertFalse($eligibility->isEligible($incident->fresh(['order'])));

        app(InquiryOrderLinkService::class)->linkToOrder($incident, $rdOrder, $admin);

        config([
            'interakt.templates.request_serial_number.name' => 'request_serial_number',
        ]);

        $this->assertTrue($eligibility->isEligible($incident->fresh(['order'])));
    }

    public function test_customer360_timeline_includes_enquiry_and_rd_history(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08854', phone: '9876543214');
        $inquiryOrder = $incident->order;
        $inquiryOrder->update(['payment_date' => now()->subDay()]);

        $rdOrder = $this->createRdOrder('RD3446004', phone: '9876543214', serial: 'SN-RD-004');
        $rdOrder->update(['payment_date' => now()]);

        $linked = app(InquiryOrderLinkService::class)->linkToOrder($incident, $rdOrder, $admin);

        $timeline = app(Customer360TimelineService::class)->forIncident($linked->fresh(['order', 'inquiryOriginOrder']));
        $dedupeKeys = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->pluck('dedupeKey')
            ->all();

        $this->assertContains("inquiry-origin:{$inquiryOrder->id}:payment:order:{$inquiryOrder->id}", $dedupeKeys);
        $this->assertContains("payment:order:{$rdOrder->id}", $dedupeKeys);
        $this->assertNotContains("payment:order:{$inquiryOrder->id}", $dedupeKeys);
    }

    public function test_cannot_link_rd_case_to_rd_order(): void
    {
        $admin = $this->createAdmin();
        $rdIncident = $this->createRdIncident($admin, 'SC08855', 'RD-SOURCE-1');
        $targetOrder = $this->createRdOrder('RD3446005');

        $this->expectException(ValidationException::class);

        app(InquiryOrderLinkService::class)->linkToOrder($rdIncident, $targetOrder, $admin);
    }

    public function test_cannot_link_inquiry_to_inq_order(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08856');
        $targetInquiry = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference('SC09999'),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->expectException(ValidationException::class);

        app(InquiryOrderLinkService::class)->linkToOrder($incident, $targetInquiry, $admin);
    }

    public function test_duplicate_active_case_on_target_order_is_blocked(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createInquiryIncident($admin, 'SC08857', phone: '9876543217');
        $rdOrder = $this->createRdOrder('RD3446007', phone: '9876543217');

        $this->createRdIncident($admin, 'SC08858', 'RD3446007', orderPk: $rdOrder->id);

        $this->expectException(ValidationException::class);

        app(InquiryOrderLinkService::class)->linkToOrder($incident, $rdOrder, $admin);
    }

    public function test_intake_prefers_linking_open_inquiry_over_creating_duplicate_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $inquiry = $this->createInquiryIncident($agent, 'SC08859', phone: '9876543218');
        $inquiryOrderId = $inquiry->order_id;
        $rdOrder = $this->createRdOrder('RD3446008', phone: '9876543218');

        $response = $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'existing_order',
            'matched_order_id' => $rdOrder->id,
            'phone' => '9876543218',
            'source' => IncidentSource::Call->value,
            'notes' => 'Customer provided real order ID.',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame(1, Incident::query()->count());

        $fresh = $inquiry->fresh(['order', 'inquiryOriginOrder']);
        $this->assertSame('SC08859', $fresh->reference_no);
        $this->assertSame($rdOrder->id, $fresh->order_id);
        $this->assertSame($inquiryOrderId, $fresh->inquiry_origin_order_id);
    }

    public function test_workspace_link_order_action_links_inquiry_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createInquiryIncident($agent, 'SC08860', phone: '9876543219', assignTo: $agent);
        $rdOrder = $this->createRdOrder('RD3446009', phone: '9876543219');

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.link-order', $incident), [
                'order_id' => $rdOrder->order_id,
                'confirmed' => '1',
                'workspace_context' => 'customer',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $incident->fresh();
        $this->assertSame($rdOrder->id, $fresh->order_id);
        $this->assertSame('SC08860', $fresh->reference_no);
    }

    public function test_customer_intake_new_contact_still_creates_inquiry(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::GeneralSupport->value,
            'customer_name' => 'Unknown Caller',
            'phone' => '9000000001',
            'source' => IncidentSource::Call->value,
            'notes' => 'General enquiry.',
        ])->assertRedirect(route('dashboard'));

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertTrue($incident->order?->isInquiryOrder() ?? false);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createInquiryIncident(
        User $creator,
        string $referenceNo,
        ?string $phone = null,
        ?User $assignTo = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => Order::inquiryOrderIdFromReference($referenceNo),
            'customer_phone' => $phone,
            'serial_number' => '',
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

    private function createRdOrder(
        string $orderId,
        ?string $phone = null,
        ?string $serial = 'SN-RD-DEFAULT',
    ): Order {
        $creator = User::query()->first() ?? User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_phone' => $phone,
            'serial_number' => $serial,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function createRdIncident(
        User $creator,
        string $referenceNo,
        string $orderId,
        ?int $orderPk = null,
    ): Incident {
        $order = $orderPk !== null
            ? Order::query()->findOrFail($orderPk)
            : $this->createRdOrder($orderId);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'RD service case',
            'description' => 'RD service case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
