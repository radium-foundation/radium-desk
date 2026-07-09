<?php

namespace Tests\Unit\Notifications;

use App\Enums\BonvoiceCallLinkType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\IncidentBonvoiceCallLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceMissedCallRecoveryService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\CustomerAutomationEligibilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAutomationEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_allows_real_rd_customer_cases(): void
    {
        $incident = $this->createIncident(orderId: 'RD3421001');

        $service = app(CustomerAutomationEligibilityService::class);

        $this->assertTrue($service->allowsAutomatedCustomerNotification($incident));
        $this->assertNull($service->blockReason($incident));
    }

    public function test_blocks_unverified_inquiry_recovery_cases(): void
    {
        $incident = $this->createIncident(
            orderId: Order::inquiryOrderIdFromReference('SC08700'),
            category: BonvoiceMissedCallRecoveryService::CATEGORY,
        );

        $service = app(CustomerAutomationEligibilityService::class);

        $this->assertFalse($service->allowsAutomatedCustomerNotification($incident));
        $this->assertSame('unverified_inquiry_recovery', $service->blockReason($incident));
    }

    public function test_blocks_noinput_spam_enquiry_cases(): void
    {
        $incident = $this->createIncident(
            orderId: Order::inquiryOrderIdFromReference('SC08701'),
            category: 'General Enquiry',
        );

        $event = BonvoiceCallEvent::query()->create([
            'call_id' => 'call-noinput-spam-001',
            'leg' => 'A',
            'event_id' => 'evt-noinput-spam-001',
            'status' => 'NOINPUT',
            'direction' => 'Inbound',
            'customer_phone' => '9123456789',
            'payload' => [],
        ]);

        IncidentBonvoiceCallLink::query()->create([
            'incident_id' => $incident->id,
            'bonvoice_call_event_id' => $event->id,
            'call_id' => $event->call_id,
            'link_type' => BonvoiceCallLinkType::Missed,
            'linked_at' => now(),
        ]);

        $service = app(CustomerAutomationEligibilityService::class);

        $this->assertFalse($service->allowsAutomatedCustomerNotification($incident->fresh()));
        $this->assertSame('noinput_spam_enquiry', $service->blockReason($incident->fresh()));
    }

    private function createIncident(string $orderId, string $category = 'General'): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => str_starts_with($orderId, 'INQ-') ? '' : 'SN-'.$orderId,
            'product_name' => str_starts_with($orderId, 'INQ-') ? null : 'MFS 110',
            'device_model' => str_starts_with($orderId, 'INQ-') ? null : 'MFS 110',
            'customer_phone' => '9123456789',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => $category,
            'source' => IncidentSource::Call,
            'title' => 'Eligibility test',
            'description' => 'Eligibility test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $creator->id,
        ]);
    }
}
