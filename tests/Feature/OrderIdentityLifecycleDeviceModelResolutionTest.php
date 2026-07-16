<?php

namespace Tests\Feature;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\ReferenceNumberCommunicationService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\OrderIdentityLifecycleService;
use App\Services\RadiumBox\RadiumBoxService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderIdentityLifecycleDeviceModelResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.driver_installation_guide.name' => 'driver_installation_guide_template',
            'interakt.templates.driver_installation_guide.display_name' => 'Driver Installation Guide',
            'interakt.templates.driver_installation_guide.language_code' => 'en',
            'interakt.templates.driver_installation_guide.enabled' => true,
        ]);
    }

    public function test_identity_lifecycle_assigns_device_model_id_when_alias_resolves(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'FM 220')->first()
            ?? DeviceModel::query()->create([
                'name' => 'FM 220',
                'driver_download_url' => 'https://ra8.in/driver-fm220',
                'display_order' => 10,
                'is_active' => true,
            ]);

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access FM220 L1',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-MODEL-1',
            'serial_number' => 'SN-AUTO-MODEL-1',
            'device_model' => 'Access FM220 L1',
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        app(OrderIdentityLifecycleService::class)->afterIdentityFieldsChanged(
            order: $order,
            actor: $admin,
            source: 'test_identity_lifecycle',
            changedFields: ['device_model'],
        );

        $order->refresh();

        $this->assertSame($deviceModel->id, $order->device_model_id);
        $this->assertSame($deviceModel->name, $order->device_model);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'device-model.bulk-assigned',
            'auditable_id' => $order->id,
        ]);
    }

    public function test_identity_lifecycle_skips_when_alias_is_missing(): void
    {
        $admin = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-MODEL-2',
            'serial_number' => 'SN-AUTO-MODEL-2',
            'device_model' => 'Unknown Vendor Model',
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        app(OrderIdentityLifecycleService::class)->afterIdentityFieldsChanged(
            order: $order,
            actor: $admin,
            source: 'test_identity_lifecycle',
            changedFields: ['device_model'],
        );

        $this->assertNull($order->fresh()->device_model_id);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'device-model.bulk-assigned',
            'auditable_id' => $order->id,
        ]);
    }

    public function test_identity_lifecycle_skips_when_device_model_id_already_set(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $otherModel = DeviceModel::query()->where('name', '!=', 'MFS110')->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $otherModel->id,
            'alias' => 'Access FM220 L1',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-MODEL-3',
            'serial_number' => 'SN-AUTO-MODEL-3',
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        app(OrderIdentityLifecycleService::class)->afterIdentityFieldsChanged(
            order: $order,
            actor: $admin,
            source: 'test_identity_lifecycle',
            changedFields: ['device_model'],
        );

        $this->assertSame($deviceModel->id, $order->fresh()->device_model_id);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'device-model.bulk-assigned',
            'auditable_id' => $order->id,
        ]);
    }

    public function test_serial_only_identity_change_does_not_assign_device_model_id(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->firstOrFail();

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access FM220 L1',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-MODEL-4',
            'serial_number' => 'SN-OLD',
            'device_model' => 'Access FM220 L1',
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $order->update(['serial_number' => 'SN-NEW']);

        app(OrderIdentityLifecycleService::class)->afterIdentityFieldsChanged(
            order: $order,
            actor: $admin,
            source: 'test_identity_lifecycle',
            changedFields: ['serial_number'],
        );

        $this->assertNull($order->fresh()->device_model_id);
    }

    public function test_radiumbox_enrichment_assigns_device_model_id_for_future_orders(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250484737',
                        'product_name' => 'Access FM220 L1',
                    ],
                ],
            ]),
        ]);

        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->create([
            'name' => 'FM 220',
            'driver_download_url' => 'https://ra8.in/driver-fm220',
            'display_order' => 11,
            'is_active' => true,
        ]);

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access FM220 L1',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-ENRICH-1',
            'cashfree_payment_id' => 'cf_auto_enrich_1',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '9876543210',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order);

        $order->refresh();

        $this->assertSame('M250484737', $order->serial_number);
        $this->assertSame($deviceModel->id, $order->device_model_id);
        $this->assertSame($deviceModel->name, $order->device_model);
    }

    public function test_future_service_reference_assignment_sends_driver_guide_after_auto_model_resolution(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250999001',
                        'product_name' => 'Access FM220 L1',
                    ],
                ],
            ]),
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-driver-future'], 200),
        ]);

        $admin = $this->adminUser();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'FM 220',
            'driver_download_url' => 'https://ra8.in/driver-fm220',
            'display_order' => 12,
            'is_active' => true,
        ]);

        DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModel->id,
            'alias' => 'Access FM220 L1',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-FUTURE-DRIVER-1',
            'cashfree_payment_id' => 'cf_future_driver_1',
            'customer_email' => 'future-customer@example.com',
            'customer_phone' => '9876501234',
            'customer_name' => 'Future Customer',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Future driver guide case',
            'description' => 'Future driver guide case.',
            'status' => IncidentStatus::InProgress,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        app(RadiumBoxService::class)->enrichOrderFromBackgroundSync($order);

        $this->assertSame($deviceModel->id, $order->fresh()->device_model_id);

        $this->enableNotificationChannels();

        $this->actingAs($admin)
            ->postJson(route('orders.transaction.store', $order->fresh()), [
                'transaction_id' => 'TXN-FUTURE-DRIVER',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
            'auditable_id' => $incident->id,
        ]);

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame(
            CommunicationActionKey::DriverInstallationGuide->value,
            $dispatchAudit->new_values['communication_action_key'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT,
            'auditable_id' => $order->id,
        ]);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
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
