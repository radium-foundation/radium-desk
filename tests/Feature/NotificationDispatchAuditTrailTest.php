<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationDispatchAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.display_name' => 'Order Update',
            'interakt.templates.request_serial_number.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manual_request_serial_returns_delivery_summary_toast(): void
    {
        [$agent, $incident] = $this->makeIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'notifications.desktop.enabled' => true,
            'notifications.telegram.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-summary-001'], 200),
        ]);

        $response = $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $toastMessage = $response->json('toast.message');

        $this->assertStringContainsString('Notification sent', $toastMessage);
        $this->assertStringContainsString('✓ WhatsApp delivered', $toastMessage);
        $this->assertStringContainsString('✓ Email delivered', $toastMessage);
        $this->assertStringContainsString('Waiting state started.', $toastMessage);
    }

    public function test_manual_request_serial_persists_notification_audit_trail_and_timeline_entry(): void
    {
        [$agent, $incident] = $this->makeIncident();

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'notifications.desktop.enabled' => true,
            'whatsapp.api_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-audit-001'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $entry = $timeline->first(fn ($item) => $item->title === 'Notification sent');

        $this->assertNotNull($entry);
        $this->assertStringContainsString('✓ WhatsApp', (string) $entry->body);
    }

    public function test_manual_request_serial_returns_detailed_failure_when_all_enabled_channels_fail(): void
    {
        [$agent, $incident] = $this->makeIncident(customerEmail: null);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => false,
            'notifications.email.enabled' => true,
            'notifications.desktop.enabled' => false,
            'notifications.telegram.enabled' => false,
            'email.api_enabled' => true,
        ]);

        $response = $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $message = $response->json('toast.message');

        $this->assertStringContainsString('Notification failed', $message);
        $this->assertStringContainsString('Customer email address is not available', $message);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function makeIncident(?string $customerEmail = 'customer@example.com'): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-FEAT-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => $customerEmail,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Notification audit trail case',
            'description' => 'Notification audit trail case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
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
