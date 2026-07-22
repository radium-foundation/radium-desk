<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Mail\NotificationMail;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerNotRespondingCloseHotfixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.final_reminder_before_closure.name' => 'final_reminder_before_closure',
            'interakt.templates.final_reminder_before_closure.language_code' => 'en',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->enableNotificationChannels();
    }

    public function test_smart_delivery_send_and_close_via_email(): void
    {
        [$agent, $incident] = $this->createAssignedCase();

        Http::fake();
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::SmartDelivery,
            ))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        Mail::assertSent(NotificationMail::class);
        $this->assertDatabaseMissing('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => 'final_reminder_before_closure',
        ]);

        $outcome = ServiceCaseCloseOutcome::query()->where('incident_id', $incident->id)->first();
        $this->assertNotNull($outcome);
        $this->assertSame(ServiceCaseCloseNotificationPreference::SmartDelivery, $outcome->notification_preference);
        $this->assertSame('final_reminder_before_closure', $outcome->metadata['communication_template']);
        $this->assertSame($agent->id, $outcome->metadata['sticky_agent_user_id']);
    }

    public function test_smart_delivery_falls_back_to_whatsapp_when_email_unavailable(): void
    {
        [$agent, $incident] = $this->createAssignedCase(customerEmail: '');

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-final-reminder-wa'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::SmartDelivery,
            ))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => 'final_reminder_before_closure',
        ]);
        Mail::assertNothingSent();
    }

    public function test_failed_smart_delivery_does_not_close_case(): void
    {
        [$agent, $incident] = $this->createAssignedCase(customerEmail: '', customerPhone: '');

        Http::fake();
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::SmartDelivery,
            ))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertDatabaseMissing('service_case_close_outcomes', [
            'incident_id' => $incident->id,
        ]);
    }

    public function test_legacy_whatsapp_only_send_and_close(): void
    {
        [$agent, $incident] = $this->createAssignedCase();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-final-reminder-wa'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::WhatsApp,
            ))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => 'final_reminder_before_closure',
        ]);
        Mail::assertNothingSent();

        $outcome = ServiceCaseCloseOutcome::query()->where('incident_id', $incident->id)->first();
        $this->assertNotNull($outcome);
        $this->assertSame(ServiceCaseCloseNotificationPreference::WhatsApp, $outcome->notification_preference);
        $this->assertSame('final_reminder_before_closure', $outcome->metadata['communication_template']);
        $this->assertSame($agent->id, $outcome->metadata['sticky_agent_user_id']);
    }

    public function test_email_only_send_and_close(): void
    {
        [$agent, $incident] = $this->createAssignedCase();

        Http::fake();
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::Email,
            ))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        Mail::assertSent(NotificationMail::class);
        $this->assertDatabaseMissing('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => 'final_reminder_before_closure',
        ]);
    }

    public function test_both_channels_send_and_close(): void
    {
        [$agent, $incident] = $this->createAssignedCase();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-final-reminder-both'], 200),
        ]);
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::Both,
            ))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        Mail::assertSent(NotificationMail::class);
        $this->assertDatabaseHas('whatsapp_template_dispatches', [
            'incident_id' => $incident->id,
            'template_key' => 'final_reminder_before_closure',
        ]);
    }

    public function test_failed_dispatch_does_not_close_case(): void
    {
        [$agent, $incident] = $this->createAssignedCase();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['error' => 'failed'], 500),
        ]);
        Mail::fake();

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), $this->closePayload(
                ServiceCaseCloseNotificationPreference::WhatsApp,
            ))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertDatabaseMissing('service_case_close_outcomes', [
            'incident_id' => $incident->id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function closePayload(ServiceCaseCloseNotificationPreference $preference): array
    {
        return [
            'workspace_context' => WorkspaceContext::ServiceCase->value,
            'action_type' => WorkspaceActionType::Close->value,
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
            'cnr_communication_preference' => $preference->value,
            'body' => 'Final reminder sent; closing as customer not responding.',
        ];
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createAssignedCase(
        string $customerEmail = 'cnr-hotfix@example.com',
        string $customerPhone = '9123456782',
    ): array {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CNR-HOTFIX-'.uniqid(),
            'serial_number' => '9620545',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'CNR Hotfix Customer',
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'transaction_id' => 'TXN-CNR-HOTFIX',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'CNR hotfix case',
            'description' => 'CNR hotfix case.',
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
}
