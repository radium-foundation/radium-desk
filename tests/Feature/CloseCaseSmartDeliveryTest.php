<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CloseCaseSmartDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.repair_completed.name' => 'repair_completed',
            'interakt.templates.repair_completed.enabled' => true,
            'interakt.templates.repair_completed.language_code' => 'en',
            'interakt.templates.final_reminder_before_closure.name' => 'final_reminder_before_closure',
            'interakt.templates.final_reminder_before_closure.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_close_v2_ui_renders_smart_delivery_option(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'action',
                'context' => WorkspaceContext::ServiceCase->value,
                'action' => WorkspaceActionType::Close->value,
            ]))
            ->assertOk()
            ->assertSee('Smart Delivery (Recommended)', false)
            ->assertSee('Email is preferred. If email is unavailable or sending fails, WhatsApp will be used automatically.', false)
            ->assertDontSee('name="notification_preference" value="whatsapp"', false)
            ->assertDontSee('name="notification_preference" value="both"', false);
    }

    public function test_smart_delivery_sends_email_when_available(): void
    {
        [$admin, $incident] = $this->createClosableIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake();
        Mail::fake();

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::IssueResolved->value,
                'resolution_type' => ServiceCaseCloseResolutionType::DeviceWorking->value,
                'notification_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Device confirmed working.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(NotificationMail::class);
        Http::assertNothingSent();

        $this->assertStringContainsString('Notification sent via Email.', $response->json('message'));

        $auditLog = $this->latestNotificationAudit($incident);
        $this->assertSame('smart_delivery', $auditLog->new_values['delivery_strategy'] ?? null);
        $this->assertSame('email', $auditLog->new_values['preferred_channel'] ?? null);
        $this->assertSame('email', $auditLog->new_values['actual_channel'] ?? null);
        $this->assertNull($auditLog->new_values['fallback_reason'] ?? null);
        $this->assertSame('Notification sent via Email.', $auditLog->new_values['timeline_summary'] ?? null);
    }

    public function test_smart_delivery_falls_back_to_whatsapp_when_email_unavailable(): void
    {
        [$admin, $incident] = $this->createClosableIncident([
            'customer_email' => '',
        ]);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-smart-fallback'], 200),
        ]);
        Mail::fake();

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::IssueResolved->value,
                'notification_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Resolved without email.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
            'status' => WhatsAppTemplateDispatchStatus::Sent->value,
        ]);

        $this->assertStringContainsString('Notification sent via WhatsApp (Email unavailable).', $response->json('message'));

        $auditLog = $this->latestNotificationAudit($incident);
        $this->assertSame('whatsapp', $auditLog->new_values['actual_channel'] ?? null);
        $this->assertSame('email_unavailable', $auditLog->new_values['fallback_reason'] ?? null);
    }

    public function test_smart_delivery_falls_back_to_whatsapp_when_email_delivery_fails(): void
    {
        [$admin, $incident] = $this->createClosableIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-smart-email-fail'], 200),
        ]);

        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP timeout'));

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerCancelled->value,
                'notification_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Customer cancelled after email failure.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
        ]);

        $this->assertStringContainsString('Notification sent via WhatsApp (Email delivery failed).', $response->json('message'));

        $auditLog = $this->latestNotificationAudit($incident);
        $this->assertSame('email_delivery_failed', $auditLog->new_values['fallback_reason'] ?? null);
    }

    public function test_smart_delivery_shows_failure_when_both_channels_fail_for_post_close(): void
    {
        [$admin, $incident] = $this->createClosableIncident([
            'customer_email' => '',
            'customer_phone' => '',
        ]);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake();
        Mail::fake();

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::Other->value,
                'notification_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Closed with notification failure.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertStringContainsString('Notification failed', $response->json('message'));
        $this->assertStringContainsString('Email:', $response->json('message'));
        $this->assertStringContainsString('WhatsApp:', $response->json('message'));
        $this->assertSame('danger', $response->json('toast.variant'));
    }

    public function test_cnr_smart_delivery_blocks_close_when_both_channels_fail(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CNR-SMART-FAIL',
            'serial_number' => '9620545',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'CNR Smart Fail',
            'customer_email' => '',
            'customer_phone' => '',
            'transaction_id' => 'TXN-CNR-SMART-FAIL',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'CNR smart delivery failure',
            'description' => 'CNR smart delivery failure.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake();
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
                'cnr_communication_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Unable to reach customer.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['cnr_communication_preference']);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertDatabaseMissing('service_case_close_outcomes', [
            'incident_id' => $incident->id,
        ]);
    }

    public function test_cnr_smart_delivery_sends_final_reminder_via_whatsapp_fallback_and_closes(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CNR-SMART-WA',
            'serial_number' => '9620545',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'CNR Smart WA',
            'customer_email' => '',
            'customer_phone' => '9123456782',
            'transaction_id' => 'TXN-CNR-SMART-WA',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'CNR smart WA fallback',
            'description' => 'CNR smart WA fallback.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-cnr-smart-wa'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
                'cnr_communication_preference' => ServiceCaseCloseNotificationPreference::SmartDelivery->value,
                'body' => 'Final reminder via smart delivery.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);

        $auditLog = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->latest('id')
            ->first();

        $this->assertSame(NotificationType::FinalReminderBeforeClosure->value, $auditLog?->new_values['notification_type'] ?? null);
        $this->assertSame('whatsapp', $auditLog?->new_values['actual_channel'] ?? null);

        $outcome = ServiceCaseCloseOutcome::query()->where('incident_id', $incident->id)->first();
        $this->assertSame(ServiceCaseCloseNotificationPreference::SmartDelivery, $outcome?->notification_preference);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Incident}
     */
    private function createClosableIncident(array $overrides = []): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return [$admin, $this->createIncident($admin, $overrides)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createIncident(User $creator, array $overrides = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-SMART-1',
            'serial_number' => $overrides['serial_number'] ?? '9620545',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Doe',
            'customer_phone' => $overrides['customer_phone'] ?? '9876543210',
            'customer_email' => $overrides['customer_email'] ?? 'customer@example.com',
            'transaction_id' => $overrides['transaction_id'] ?? 'TXN-SMART-1',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        unset(
            $overrides['order_id'],
            $overrides['serial_number'],
            $overrides['customer_phone'],
            $overrides['customer_email'],
            $overrides['transaction_id'],
        );

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Smart delivery test',
            'description' => 'Smart delivery test.',
            'status' => $overrides['status'] ?? IncidentStatus::InProgress,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    private function latestNotificationAudit(Incident $incident): AuditLog
    {
        return AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @param  array<string, bool>  $channels
     */
    private function enableNotificationChannels(array $channels): void
    {
        foreach ($channels as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );

            app(SystemSettingsService::class)->forget($key);
        }
    }
}
