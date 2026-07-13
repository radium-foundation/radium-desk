<?php

namespace Tests\Unit\CommunicationActions\ReviewRequest;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\ReviewRequest\ReviewRequestEligibilityService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewRequestEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_is_eligible_when_incident_is_resolved_and_customer_has_contact(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Resolved);

        $this->assertNull(app(ReviewRequestEligibilityService::class)->ineligibilityReason($incident));
        $this->assertTrue(app(CommunicationActionEligibilityService::class)->canShowAction(
            app(CommunicationActionRegistry::class)->get(CommunicationActionKey::ReviewRequest),
            $incident,
            $agent,
        ));
    }

    public function test_is_eligible_when_incident_is_closed(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Closed);

        $this->assertNull(app(ReviewRequestEligibilityService::class)->ineligibilityReason($incident));
    }

    public function test_is_eligible_when_support_work_is_completed_on_open_incident(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Open);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $this->assertNull(app(ReviewRequestEligibilityService::class)->ineligibilityReason($incident->fresh('supportAppointments')));
    }

    public function test_is_ineligible_when_support_is_still_in_progress(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::InProgress);

        $reason = app(ReviewRequestEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Review requests can be sent after support work is completed or the service case is resolved.',
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
            customerPhone: '',
            customerEmail: '',
        );

        $reason = app(ReviewRequestEligibilityService::class)->ineligibilityReason($incident);

        $this->assertSame(
            'Customer contact details are required before sending a review request.',
            $reason,
        );
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        IncidentStatus $status,
        string $customerPhone = '9876543210',
        string $customerEmail = 'customer@example.com',
    ): array {
        $order = Order::query()->create([
            'order_id' => 'RD-REVIEW-ELIG',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
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
            'title' => 'Review request eligibility case',
            'description' => 'Review request eligibility case.',
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }
}
