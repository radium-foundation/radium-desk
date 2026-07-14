<?php

namespace Tests\Unit\CommunicationActions\RefundConfirmation;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\RefundConfirmation\RefundConfirmationEligibilityService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundConfirmationEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_is_eligible_when_refund_is_approved_and_customer_has_contact(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);
        $this->createApprovedRefund($incident, $admin);

        $this->assertNull(app(RefundConfirmationEligibilityService::class)->ineligibilityReason($incident));
        $this->assertTrue(app(CommunicationActionEligibilityService::class)->canShowAction(
            app(CommunicationActionRegistry::class)->get(CommunicationActionKey::RefundConfirmation),
            $incident,
            $admin,
        ));
    }

    public function test_is_eligible_when_approved_refund_is_linked_to_order_only(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);

        RefundRequest::query()->create([
            'order_id' => $incident->order_id,
            'reference_no' => 'REF-2026-000100',
            'amount' => 1500,
            'reason' => 'Approved refund for order-linked case.',
            'status' => RefundStatus::Approved,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-100',
        ]);

        $this->assertNull(app(RefundConfirmationEligibilityService::class)->ineligibilityReason($incident));
    }

    public function test_is_ineligible_without_approved_refund(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);

        $reason = app(RefundConfirmationEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Refund confirmation can be sent only after a refund has been completed for this case.',
            $reason,
        );
    }

    public function test_is_ineligible_when_refund_is_still_pending(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);

        RefundRequest::query()->create([
            'order_id' => $incident->order_id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000101',
            'amount' => 500,
            'reason' => 'Pending refund should not unlock confirmation.',
            'status' => RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $reason = app(RefundConfirmationEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Refund confirmation can be sent only after a refund has been completed for this case.',
            $reason,
        );
    }

    public function test_is_ineligible_without_customer_contact(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident(
            actor: $admin,
            customerPhone: '',
            customerEmail: '',
        );
        $this->createApprovedRefund($incident, $admin);

        $reason = app(RefundConfirmationEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Customer contact details are required before sending a refund confirmation.',
            $reason,
        );
    }

    public function test_is_ineligible_without_refund_amount(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);

        RefundRequest::query()->create([
            'order_id' => $incident->order_id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000102',
            'amount' => 0,
            'reason' => 'Zero amount refund should not unlock confirmation.',
            'status' => RefundStatus::Approved,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-102',
        ]);

        $reason = app(RefundConfirmationEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'A refund amount is required before sending a refund confirmation.',
            $reason,
        );
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        string $customerPhone = '9876543210',
        string $customerEmail = 'customer@example.com',
    ): array {
        $order = Order::query()->create([
            'order_id' => 'RD-REFUND-ELIG',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_name' => 'Refund Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Refund confirmation eligibility case',
            'description' => 'Refund confirmation eligibility case.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }

    private function createApprovedRefund(Incident $incident, User $actor): RefundRequest
    {
        return RefundRequest::query()->create([
            'order_id' => $incident->order_id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000200',
            'amount' => 2500.50,
            'reason' => 'Approved refund for communication action.',
            'status' => RefundStatus::Approved,
            'requested_by' => $actor->id,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-200',
        ]);
    }
}
