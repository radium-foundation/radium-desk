<?php

namespace Tests\Feature\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\WorkspaceContext;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\CommunicationActions\ReferenceNumberCommunicationService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenceNumberAddedDriverInstallationGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.driver_installation_guide.name' => 'driver_installation_guide_template',
            'interakt.templates.driver_installation_guide.display_name' => 'Driver Installation Guide',
            'interakt.templates.driver_installation_guide.language_code' => 'en',
            'interakt.templates.driver_installation_guide.enabled' => true,
        ]);
    }

    public function test_assigning_reference_number_triggers_driver_installation_guide(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$order, $incident] = $this->createOrderWithIncident($admin);

        $this->verifyLegacyOrder($admin, $order);
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-driver-ref-001'], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-DRIVER-AUTO-1',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseHas('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('driver_installation_guide', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame('automation', $dispatchAudit->new_values['source']);

        $this->assertDatabaseHas('audit_logs', [
            'event' => ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);

        $statuses = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->where('auditable_id', $incident->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $auditLog): string => (string) ($auditLog->new_values['status'] ?? ''))
            ->all();

        $this->assertSame(['sent', 'completed'], $statuses);
    }

    public function test_failed_reference_number_save_does_not_send_driver_installation_guide(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$order] = $this->createOrderWithIncident($admin);

        $this->enableNotificationChannels();

        Http::fake();

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => '',
            ])
            ->assertSessionHasErrors('transaction_id');

        $this->assertDatabaseMissing('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);
    }

    public function test_driver_installation_guide_is_skipped_without_driver_link(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'Unknown Model',
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$order] = $this->createOrderWithIncident($admin, $deviceModel);

        $this->verifyLegacyOrder($admin, $order);
        $this->enableNotificationChannels();

        Http::fake();

        $this->actingAs($admin)
            ->post(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-NO-DRIVER',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseMissing('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'event' => ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT,
        ]);
    }

    public function test_duplicate_reference_number_submission_does_not_resend_driver_installation_guide(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$order] = $this->createOrderWithIncident($admin);

        $this->verifyLegacyOrder($admin, $order);
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-driver-ref-dup'], 200),
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-DRIVER-DUP',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('orders.transaction.store', $order), [
                'transaction_id' => 'TXN-DRIVER-DUP',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
                ->where('new_values->communication_action_key', CommunicationActionKey::DriverInstallationGuide->value)
                ->count(),
        );
    }

    public function test_batch_assignment_sends_one_driver_installation_guide_per_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110 Batch',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$firstOrder, $firstIncident] = $this->createOrderWithIncident($admin, $deviceModel);
        [$secondOrder, $secondIncident] = $this->createOrderWithIncident($admin, $deviceModel);

        $this->verifyLegacyOrder($admin, $firstOrder);
        $this->verifyLegacyOrder($admin, $secondOrder);
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-driver-batch'], 200),
        ]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$firstIncident->id, $secondIncident->id],
                'transaction_id' => 'TXN-BATCH-DRIVER',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
                ->where('new_values->communication_action_key', CommunicationActionKey::DriverInstallationGuide->value)
                ->count(),
        );

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('event', ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT)
                ->count(),
        );

        $this->assertSame('TXN-BATCH-DRIVER', $firstOrder->fresh()->transaction_id);
        $this->assertSame('TXN-BATCH-DRIVER', $secondOrder->fresh()->transaction_id);
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function createOrderWithIncident(User $actor, ?DeviceModel $deviceModel = null): array
    {
        $deviceModel ??= DeviceModel::query()->create([
            'name' => 'MFS 110',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-REF-DRIVER-'.uniqid(),
            'serial_number' => 'SN-REF-DRIVER-'.uniqid(),
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
            'title' => 'Reference number driver guide case',
            'description' => 'Reference number driver guide case.',
            'status' => IncidentStatus::InProgress,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$order, $incident];
    }

    private function verifyLegacyOrder(User $admin, Order $order): void
    {
        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();
    }

    /**
     * @param  array<string, bool>  $settings
     */
    private function enableNotificationChannels(array $settings = []): void
    {
        $settings = array_merge([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ], $settings);

        foreach ($settings as $key => $value) {
            \App\Models\SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value ? '1' : '0'],
            );

            app(\App\Services\SystemSettingsService::class)->forget($key);
        }
    }
}
