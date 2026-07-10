<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceCaseCloseException;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkspaceCloseNotificationTest extends TestCase
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
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_close_with_whatsapp_checked_sends_repair_completed_template(): void
    {
        [$admin, $incident] = $this->createClosableIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-close-wa-001'], 200),
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer confirmed resolution.',
                'notify_whatsapp' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertStringContainsString('Service case closed.', $response->json('message'));
        $this->assertStringContainsString('Notification sent', $response->json('message'));

        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
            'status' => WhatsAppTemplateDispatchStatus::Sent->value,
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($incident): bool {
            $payload = json_decode((string) $request->body(), true);

            return ($payload['template']['name'] ?? null) === 'repair_completed'
                && ($payload['template']['bodyValues'] ?? null) === ['Jane Doe', $incident->reference_no];
        });

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);

        $auditLog = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->latest('id')
            ->first();

        $this->assertSame(NotificationType::ServiceCaseClosed->value, $auditLog?->new_values['notification_type'] ?? null);
        $this->assertSame('workspace_close', $auditLog?->new_values['source'] ?? null);
    }

    public function test_close_with_email_checked_sends_email(): void
    {
        [$admin, $incident] = $this->createClosableIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => false,
            'notifications.email.enabled' => true,
            'email.api_enabled' => true,
        ]);

        Mail::fake();

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer confirmed resolution.',
                'notify_email' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(NotificationMail::class);

        $auditLog = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->latest('id')
            ->first();

        $emailResult = collect($auditLog->new_values['channel_results'] ?? [])
            ->firstWhere('channel', 'email');

        $this->assertNotNull($auditLog);
        $this->assertNotNull($emailResult);
        $this->assertTrue($emailResult['success'] ?? false);

        $this->assertDatabaseMissing('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
        ]);

        $channels = collect($auditLog->new_values['channel_results'] ?? [])->pluck('channel')->all();
        $this->assertSame(['email'], $channels);
    }

    public function test_close_with_both_notify_flags_sends_whatsapp_and_email(): void
    {
        [$admin, $incident] = $this->createClosableIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-close-both-001'], 200),
        ]);

        Mail::fake();

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer confirmed resolution.',
                'notify_whatsapp' => true,
                'notify_email' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(NotificationMail::class);

        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
            'status' => WhatsAppTemplateDispatchStatus::Sent->value,
        ]);

        $auditLog = AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->latest('id')
            ->first();

        $channels = collect($auditLog->new_values['channel_results'] ?? [])->pluck('channel')->all();
        $this->assertEqualsCanonicalizing(['whatsapp', 'email'], $channels);
    }

    public function test_close_without_notify_flags_sends_nothing(): void
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

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer confirmed resolution.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service case closed.');

        Http::assertNothingSent();
        Mail::assertNothingSent();

        $this->assertDatabaseMissing('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);
    }

    public function test_exception_close_still_stores_notify_flags(): void
    {
        $this->travelTo('2026-07-01 12:00:00');

        [$admin, $incident] = $this->createClosableIncident([
            'serial_number' => null,
            'reference_no' => '',
        ]);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-close-exc-001'], 200),
        ]);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Closing with documented exception.',
                'serial_number_unavailable' => true,
                'reference_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
                'reference_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
                'notify_whatsapp' => true,
                'notify_email' => true,
            ])
            ->assertOk();

        $exceptions = ServiceCaseCloseException::query()
            ->where('incident_id', $incident->id)
            ->get();

        $this->assertCount(2, $exceptions);
        $this->assertTrue($exceptions->every(
            fn (ServiceCaseCloseException $exception): bool => $exception->notify_whatsapp && $exception->notify_email,
        ));

        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => WhatsAppTemplate::RepairCompleted->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Incident}
     */
    private function createClosableIncident(array $overrides = []): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-CLOSE-NOTIF-1',
            'serial_number' => array_key_exists('serial_number', $overrides)
                ? $overrides['serial_number']
                : 'SN-CLOSE-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Doe',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => array_key_exists('reference_no', $overrides)
                ? $overrides['reference_no']
                : app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Close notification test',
            'description' => 'Close notification test.',
            'status' => $overrides['status'] ?? IncidentStatus::InProgress,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        return [$admin, $incident];
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
