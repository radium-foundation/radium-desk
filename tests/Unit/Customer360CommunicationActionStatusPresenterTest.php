<?php

namespace Tests\Unit;

use App\Enums\CommunicationActionKey;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\IncidentReferenceService;
use App\Support\AppDateFormatter;
use App\Support\Customer360\Customer360CommunicationActionStatusPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360CommunicationActionStatusPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_shows_available_when_action_is_eligible_and_never_sent(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Resolved);

        $statuses = $this->present($incident, $agent);
        $reviewRequest = $this->findAction($statuses, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame('available', $reviewRequest['status']);
        $this->assertSame('Available', $reviewRequest['status_label']);
        $this->assertSame('info', $reviewRequest['status_variant']);
        $this->assertTrue($reviewRequest['eligible']);
        $this->assertFalse($reviewRequest['show_already_sent']);
        $this->assertSame('Send Review Request', $reviewRequest['display_name']);
        $this->assertTrue($reviewRequest['show_chevron']);
        $this->assertNull($reviewRequest['helper_text']);
    }

    public function test_shows_sent_today_after_successful_execution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 14:00:00', AppDateFormatter::timezone()));

        [$agent, $incident] = $this->createIncident(IncidentStatus::Resolved);

        app(CommunicationActionLifecycleService::class)->recordSuccessfulExecution(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::ReviewRequest->value,
            channels: ['whatsapp'],
        );

        $statuses = $this->present($incident, $agent);
        $reviewRequest = $this->findAction($statuses, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame('sent', $reviewRequest['status']);
        $this->assertSame('Sent today', $reviewRequest['status_label']);
        $this->assertSame('success', $reviewRequest['status_variant']);
        $this->assertSame('✓', $reviewRequest['status_icon']);
        $this->assertFalse($reviewRequest['show_already_sent']);
    }

    public function test_shows_sent_yesterday_for_previous_day_send(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Resolved);

        Carbon::setTestNow(Carbon::parse('2026-07-12 16:00:00', AppDateFormatter::timezone()));

        app(CommunicationActionLifecycleService::class)->recordSuccessfulExecution(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::ReviewRequest->value,
            channels: ['whatsapp'],
        );

        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00', AppDateFormatter::timezone()));

        $statuses = $this->present($incident, $agent);
        $reviewRequest = $this->findAction($statuses, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame('sent', $reviewRequest['status']);
        $this->assertSame('Sent yesterday', $reviewRequest['status_label']);
    }

    public function test_shows_not_eligible_with_disabled_reason(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::InProgress);

        $statuses = $this->present($incident, $agent);
        $reviewRequest = $this->findAction($statuses, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame('not_eligible', $reviewRequest['status']);
        $this->assertSame(
            'Review requests can be sent after support work is completed or the service case is resolved.',
            $reviewRequest['status_label'],
        );
        $this->assertSame('muted', $reviewRequest['status_variant']);
        $this->assertFalse($reviewRequest['eligible']);
        $this->assertSame('Send Review Request', $reviewRequest['display_name']);
        $this->assertFalse($reviewRequest['clickable']);
        $this->assertSame(
            'Review requests can be sent after support work is completed or the service case is resolved.',
            $reviewRequest['helper_text'],
        );
    }

    public function test_shows_role_based_not_eligible_reason_for_refund_confirmation(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Resolved);

        $statuses = $this->present($incident, $agent);
        $refund = $this->findAction($statuses, CommunicationActionKey::RefundConfirmation->value);

        $this->assertSame('not_eligible', $refund['status']);
        $this->assertSame(
            'You do not have permission to run this communication action.',
            $refund['status_label'],
        );
    }

    public function test_shows_already_sent_indicator_when_latest_lifecycle_state_is_sent(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Resolved);

        app(CommunicationActionLifecycleService::class)->recordSent(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::ReviewRequest->value,
            channels: ['whatsapp'],
        );

        $statuses = $this->present($incident, $agent);
        $reviewRequest = $this->findAction($statuses, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame('sent', $reviewRequest['status']);
        $this->assertTrue($reviewRequest['show_already_sent']);
        $this->assertSame('Already Sent', $reviewRequest['already_sent_label']);
    }

    public function test_driver_installation_guide_shows_not_eligible_without_driver_url(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Open);

        $statuses = $this->present($incident, $agent);
        $driverGuide = $this->findAction($statuses, CommunicationActionKey::DriverInstallationGuide->value);

        $this->assertSame('not_eligible', $driverGuide['status']);
        $this->assertSame(
            'No driver download link is available for this device model.',
            $driverGuide['status_label'],
        );
    }

    public function test_driver_installation_guide_shows_sent_status_when_previously_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:30:00', AppDateFormatter::timezone()));

        [$agent, $incident] = $this->createIncidentWithDriverModel();

        app(CommunicationActionLifecycleService::class)->recordSuccessfulExecution(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::DriverInstallationGuide->value,
            channels: ['email'],
        );

        $statuses = $this->present($incident, $agent);
        $driverGuide = $this->findAction($statuses, CommunicationActionKey::DriverInstallationGuide->value);

        $this->assertSame('sent', $driverGuide['status']);
        $this->assertSame('Sent today', $driverGuide['status_label']);
    }

    public function test_completed_support_appointment_makes_review_request_available_on_open_incident(): void
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Open);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
            'normalized_phone' => '9876543210',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $statuses = $this->present($incident->fresh('supportAppointments'), $agent);
        $reviewRequest = $this->findAction($statuses, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame('available', $reviewRequest['status']);
        $this->assertSame('Available', $reviewRequest['status_label']);
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    private function findAction(array $statuses, string $key): array
    {
        $action = collect($statuses)->firstWhere('key', $key);

        $this->assertNotNull($action, "Expected communication action [{$key}] in presenter output.");

        return $action;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(Incident $incident, User $user): array
    {
        return app(Customer360CommunicationActionStatusPresenter::class)->forIncident($incident, $user);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncident(IncidentStatus $status): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-C360-STATUS',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Communication action status case',
            'description' => 'Communication action status case.',
            'status' => $status,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithDriverModel(): array
    {
        [$agent, $incident] = $this->createIncident(IncidentStatus::Open);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $incident->order?->update([
            'device_model_id' => $deviceModel->id,
            'device_model' => $deviceModel->name,
        ]);

        return [$agent, $incident->fresh('order')];
    }
}
