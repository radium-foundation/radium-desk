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
use App\Services\CommunicationActions\CommunicationActionTargetProviderRegistry;
use App\Enums\RefundStatus;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class CommunicationCenterV2Test extends TestCase
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
            'interakt.templates.review_request.name' => 'review_request_template',
            'interakt.templates.review_request.display_name' => 'Review Request',
            'interakt.templates.review_request.language_code' => 'en',
            'interakt.templates.review_request.enabled' => true,
        ]);
    }

    public function test_customer360_shows_single_communication_overflow_entry(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-workspace-trigger="communication-action"', false)
            ->assertSee('>Communication</span>', false)
            ->assertDontSee('data-workspace-communication-action-key="driver_installation_guide"', false)
            ->assertDontSee('data-workspace-communication-action-key="review_request"', false);
    }

    public function test_communication_center_modal_loads_without_action_key(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident, $deviceModel] = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'communication-action',
            ]).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('data-communication-center-form', false)
            ->assertSee('name="communication_target"', false)
            ->assertSee('name="delivery_channel"', false)
            ->assertSee('>Communication</h2>', false)
            ->assertSee($deviceModel->name, false)
            ->assertDontSee('Interakt Template', false)
            ->assertDontSee('Confirmation', false);
    }

    public function test_refund_confirmation_uses_legacy_modal(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createApprovedRefundIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'communication-action',
            ]).'?workspace_context=customer&key='.CommunicationActionKey::RefundConfirmation->value)
            ->assertOk()
            ->assertSee('Refund Confirmation', false)
            ->assertSee('communication-action-customer-heading', false)
            ->assertDontSee('data-communication-center-form', false);
    }

    public function test_delivery_channel_whatsapp_sends_only_whatsapp(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-center-001'], 200),
        ]);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::DriverInstallationGuide->value,
            ]), [
                'workspace_context' => 'customer',
                'delivery_channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSentCount(1);
    }

    public function test_communication_target_overrides_device_model_for_driver_guide(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $alternateModel = DeviceModel::query()->create([
            'name' => 'FM220',
            'driver_download_url' => 'https://radiumbox.com/drivers/fm220',
            'display_order' => 2,
            'is_active' => true,
        ]);

        [$incident] = $this->createIncident($agent);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-center-002'], 200),
        ]);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::DriverInstallationGuide->value,
            ]), [
                'workspace_context' => 'customer',
                'communication_target' => (string) $alternateModel->id,
                'delivery_channel' => 'whatsapp',
            ])
            ->assertOk();

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('driver_installation_guide', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame((string) $alternateModel->id, $dispatchAudit->new_values['communication_target'] ?? null);
    }

    public function test_target_provider_registry_lists_active_device_models_with_driver_urls(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        DeviceModel::query()->create([
            'name' => 'Inactive Model',
            'driver_download_url' => 'https://radiumbox.com/drivers/inactive',
            'is_active' => false,
            'display_order' => 99,
        ]);

        [$incident, $deviceModel] = $this->createIncident($agent);

        $registry = app(CommunicationActionTargetProviderRegistry::class);
        $config = $registry->buildCenterConfig($incident, $agent);

        $this->assertContains($deviceModel->name, collect($config['targetsByAction']['driver_installation_guide'])->pluck('label')->all());
        $this->assertNotContains('Inactive Model', collect($config['targetsByAction']['driver_installation_guide'])->pluck('label')->all());
    }

    /**
     * @return array{0: Incident, 1: DeviceModel}
     */
    private function createIncident(User $actor): array
    {
        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-CENTER-001',
            'serial_number' => 'SN-CENTER-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'device_model_id' => $deviceModel->id,
            'customer_name' => 'Test Customer',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'status' => 'active',
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Communication center test case',
            'description' => 'Communication center test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident->fresh(['order']), $deviceModel];
    }

    /**
     * @return array{0: Incident}
     */
    private function createApprovedRefundIncident(User $admin): array
    {
        $deviceModel = DeviceModel::query()->create([
            'name' => 'Refund Model',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-REFUND-CENTER',
            'serial_number' => 'SN-REFUND',
            'product_name' => 'Refund Model',
            'device_model' => 'Refund Model',
            'device_model_id' => $deviceModel->id,
            'customer_name' => 'Refund Customer',
            'customer_phone' => '9876543211',
            'customer_email' => 'refund@example.com',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Refund communication center test case',
            'description' => 'Refund communication center test case.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        \App\Models\RefundRequest::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'reference_no' => 'RF-001',
            'amount' => 1000,
            'reason' => 'Approved refund for communication center test.',
            'status' => RefundStatus::Approved,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-CENTER',
        ]);

        return [$incident->fresh(['order'])];
    }

    /**
     * @param  array<string, mixed>  $settings
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
