<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationLinkSource;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WaitingReason;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Mail\NotificationMail;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\NotificationLinkToken;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Notifications\NotificationLinkTrackingService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\SupportAppointmentService;
use App\Services\SupportAppointmentUrlService;
use App\Services\SystemSettingsService;
use App\Notifications\ServiceCaseCustomerRespondedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerNotRespondingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.callback_schedule.name' => 'callback_schedule',
            'interakt.templates.callback_schedule.language_code' => 'en',
            'smart_assignment.enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_customer_not_responding_sends_whatsapp_and_email(): void
    {
        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-001'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk()
            ->assertJsonPath('success', true);

        $dispatch = WhatsAppTemplateDispatch::query()->first();
        $this->assertNotNull($dispatch);
        $this->assertSame('callback_schedule', $dispatch->template_name);

        Mail::assertSent(NotificationMail::class);
    }

    public function test_customer_not_responding_generates_schedule_token(): void
    {
        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-002'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $this->assertDatabaseHas('notification_link_tokens', [
            'incident_id' => $incident->id,
            'source' => NotificationLinkSource::WhatsApp->value,
        ]);
    }

    public function test_existing_schedule_url_works_for_callback_token(): void
    {
        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-003'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $token = NotificationLinkToken::query()->where('incident_id', $incident->id)->first();
        $this->assertNotNull($token);

        $response = $this->get(route('support.schedule.track', [
            'token' => $token->token,
            'source' => NotificationLinkSource::WhatsApp->value,
        ]));

        $expectedBookingUrl = app(SupportAppointmentUrlService::class)->bookingUrl($incident);
        $response->assertRedirect($expectedBookingUrl);
    }

    public function test_customer_not_responding_starts_waiting_state_with_sla_paused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-004'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $waitingState = IncidentWaitingState::query()
            ->where('incident_id', $incident->id)
            ->first();

        $this->assertNotNull($waitingState);
        $this->assertSame(WaitingReason::CustomerNotResponding, $waitingState->waiting_reason);
        $this->assertTrue($waitingState->sla_paused);
        $this->assertNotNull($waitingState->customer_followup_sent_at);
        $this->assertSame($agent->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_customer_not_responding_removes_case_from_agent_active_workload(): void
    {
        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-005'], 200),
        ]);
        Mail::fake();

        $metricsBefore = app(SmartAssignmentService::class)->workloadMetrics($agent);
        $this->assertSame(1, $metricsBefore['open_cases']);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        app(DashboardSnapshotStore::class)->forget();

        $freshIncident = $incident->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($freshIncident));
        $this->assertSame(0, app(SmartAssignmentService::class)->workloadMetrics($agent)['open_cases']);
    }

    public function test_booking_preserves_assigned_agent_after_customer_not_responding(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-006'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        app(SupportAppointmentService::class)->book($incident->fresh(), [
            'preferred_date' => '2026-07-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9123456782',
        ]);

        $freshIncident = $incident->fresh(['assignee', 'supportAppointments', 'activeWaitingState']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame($agent->id, $freshIncident->assigned_to_user_id);
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $agent));
    }

    public function test_escalation_specialist_is_not_retained_as_normal_operational_assignee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createEligibleAgent('agent@test.com', 'Normal Agent');
        $specialist = User::factory()->create([
            'name' => 'Escalation Specialist',
            'email' => 'escalation@test.com',
            'is_active' => true,
        ]);
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        [, $incident] = $this->createAssignedCase($specialist);
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-escalation'], 200),
        ]);
        Mail::fake();

        $this->actingAs($specialist)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        app(SupportAppointmentService::class)->book($incident->fresh(), [
            'preferred_date' => '2026-07-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9123456782',
        ]);

        $freshIncident = $incident->fresh(['assignee', 'supportAppointments', 'activeWaitingState']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame($agent->id, $freshIncident->assigned_to_user_id);
        $this->assertNotSame($specialist->id, $freshIncident->assigned_to_user_id);
        $this->assertFalse($classifier->matchesMyWork($freshIncident, $specialist));
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $agent));
    }

    public function test_customer_not_responding_booking_clears_waiting_state_and_wakes_agent(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        [$agent, $incident] = $this->createAssignedCase();
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-callback-schedule-booking-clear'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.customer-not-responding', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $this->assertNotNull(app(IncidentWaitingStateService::class)->activeFor($incident->fresh()));

        app(SupportAppointmentService::class)->book($incident->fresh(), [
            'preferred_date' => '2026-07-10',
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9123456782',
        ]);

        $this->assertNull(app(IncidentWaitingStateService::class)->activeFor($incident->fresh()));
        Notification::assertSentTo($agent, ServiceCaseCustomerRespondedNotification::class);
    }

    public function test_customer_not_responding_v2_close_defaults_to_smart_delivery_when_preference_omitted(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [, $incident] = $this->createAssignedCase($agent);
        $incident->order?->update(['transaction_id' => 'TXN-CNR-CLOSE']);
        $this->enableNotificationChannels();

        config([
            'interakt.templates.final_reminder_before_closure.name' => 'final_reminder_before_closure',
            'interakt.templates.final_reminder_before_closure.language_code' => 'en',
        ]);

        Http::fake();
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
                'body' => 'Customer not responding.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(NotificationMail::class);

        $outcome = \App\Models\ServiceCaseCloseOutcome::query()->where('incident_id', $incident->id)->first();
        $this->assertSame(ServiceCaseCloseNotificationPreference::SmartDelivery, $outcome?->notification_preference);
    }

    public function test_customer_not_responding_v2_close_sends_final_reminder_and_closes(): void
    {
        [$agent, $incident] = $this->createAssignedCase();
        $incident->order?->update(['transaction_id' => 'TXN-CNR-CLOSE']);
        $this->enableNotificationChannels();

        config([
            'interakt.templates.final_reminder_before_closure.name' => 'final_reminder_before_closure',
            'interakt.templates.final_reminder_before_closure.language_code' => 'en',
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-final-reminder-007'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
                'cnr_communication_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Customer not responding after final reminder.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);

        Mail::assertSent(NotificationMail::class);
        Http::assertNothingSent();

        $this->assertDatabaseHas('service_case_close_outcomes', [
            'incident_id' => $incident->id,
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
            'notification_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
            'closed_by' => $agent->id,
        ]);
    }

    public function test_request_serial_workflow_is_unchanged(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SERIAL-UNCHANGED',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Serial Customer',
            'customer_email' => 'serial@example.com',
            'customer_phone' => '9123456783',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Missing serial',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        config([
            'interakt.templates.request_serial_number.name' => 'support_schedule',
            'interakt.templates.request_serial_number.language_code' => 'en',
        ]);

        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-request-serial-unchanged'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $waitingState = IncidentWaitingState::query()->where('incident_id', $incident->id)->first();

        $this->assertNotNull($waitingState);
        $this->assertSame(WaitingReason::SerialNumber, $waitingState->waiting_reason);
        $this->assertNull($waitingState->customer_followup_sent_at);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createAssignedCase(?User $agent = null): array
    {
        $agent ??= tap(User::factory()->create(), function (User $user): void {
            $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        });

        $order = Order::query()->create([
            'order_id' => 'RD-CNR-'.uniqid(),
            'serial_number' => '9620545',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Unreachable Customer',
            'customer_email' => 'unreachable@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Customer not responding case',
            'description' => 'Could not reach customer.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function enableNotificationChannels(): void
    {
        foreach ([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ] as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );
            app(SystemSettingsService::class)->forget($key);
        }
    }

    private function createEligibleAgent(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user->fresh();
    }
}
