<?php

namespace Tests\Feature\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class DriverInstallationGuideCommunicationActionTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRequestForgeryProtection();

        $this->seed(RolePermissionSeeder::class);

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.driver_installation_guide.name' => 'driver_installation_guide_template',
            'interakt.templates.driver_installation_guide.display_name' => 'Driver Installation Guide',
            'interakt.templates.driver_installation_guide.language_code' => 'en',
            'interakt.templates.driver_installation_guide.enabled' => true,
        ]);
    }

    public function test_agent_can_execute_driver_installation_guide_and_records_audit_trail(): void
    {
        $agent = User::factory()->create(['name' => 'Guide Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-driver-001'], 200),
        ]);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::DriverInstallationGuide->value,
            ]), [
                'workspace_context' => 'customer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('driver_installation_guide', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame('Driver installation guide sent', $dispatchAudit->new_values['communication_action_label']);

        $statuses = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $auditLog): string => (string) ($auditLog->new_values['status'] ?? ''))
            ->all();

        $this->assertSame(['sent', 'completed'], $statuses);
    }

    public function test_customer360_lists_driver_installation_guide_when_eligible(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('>Communication</span>', false);
        $response->assertSee('Send Driver Installation Guide', false);
        $response->assertSee('data-workspace-communication-action-key="driver_installation_guide"', false);
        $response->assertSee('bi-chevron-right', false);
    }

    public function test_driver_installation_guide_is_hidden_without_driver_link(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'Unknown Model',
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$incident] = $this->createIncident($agent, deviceModel: $deviceModel);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::DriverInstallationGuide->value,
            ]), [
                'workspace_context' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_opening_driver_installation_guide_dialog_records_opened_lifecycle_event(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'communication-action',
            ]).'?workspace_context=customer&key='.CommunicationActionKey::DriverInstallationGuide->value)
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => CommunicationActionLifecycleAuditService::EVENT,
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
        ]);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        ?DeviceModel $deviceModel = null,
    ): array {
        $deviceModel ??= DeviceModel::query()->create([
            'name' => 'MFS 110',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-DRIVER-EXEC',
            'serial_number' => 'SN-DRIVER-EXEC',
            'product_name' => $deviceModel->name,
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Driver installation guide execution case',
            'description' => 'Driver installation guide execution case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }

    /**
     * @param  array<string, bool>  $settings
     */
    private function enableNotificationChannels(array $settings): void
    {
        foreach ($settings as $key => $value) {
            \App\Models\SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value ? '1' : '0'],
            );

            app(\App\Services\SystemSettingsService::class)->forget($key);
        }
    }
}
