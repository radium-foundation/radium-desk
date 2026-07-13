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

class BuyRdServiceCommunicationActionTest extends TestCase
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
            'interakt.templates.buy_rd_service.name' => 'buy_rd_service_template',
            'interakt.templates.buy_rd_service.display_name' => 'Buy RD Service',
            'interakt.templates.buy_rd_service.language_code' => 'en',
            'interakt.templates.buy_rd_service.enabled' => true,
        ]);
    }

    public function test_agent_can_execute_buy_rd_service_and_records_audit_trail(): void
    {
        $agent = User::factory()->create(['name' => 'Catalog Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-buy-rd-001'], 200),
        ]);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::BuyRdService->value,
            ]), [
                'workspace_context' => 'customer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('buy_rd_service', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame('RD Service purchase link sent', $dispatchAudit->new_values['communication_action_label']);

        $statuses = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $auditLog): string => (string) ($auditLog->new_values['status'] ?? ''))
            ->all();

        $this->assertSame(['sent', 'completed'], $statuses);
    }

    public function test_customer360_lists_buy_rd_service_when_eligible(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('>Communication</span>', false)
            ->assertDontSee('data-workspace-communication-action-key="buy_rd_service"', false);
    }

    public function test_buy_rd_service_is_unavailable_without_catalog_url(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, rdServiceUrl: null);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::BuyRdService->value,
            ]), [
                'workspace_context' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        ?string $rdServiceUrl = 'https://radiumbox.com/rd-service/mfs-110',
    ): array {
        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110',
            'buy_rd_service_url' => $rdServiceUrl,
            'buy_device_url' => 'https://radiumbox.com/shop/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-BUY-RD-EXEC',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'device_model_id' => $deviceModel->id,
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Catalog Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Buy RD Service execution case',
            'description' => 'Buy RD Service execution case.',
            'status' => IncidentStatus::Resolved,
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
