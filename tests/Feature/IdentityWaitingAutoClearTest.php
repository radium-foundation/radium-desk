<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\MissingSerialAutomationStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WaitingReason;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderDeviceModelService;
use App\Services\OrderIdentityLifecycleService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SystemSettingsService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityWaitingAutoClearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
            'interakt.templates.request_correct_serial.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin']);
        $this->enableNotificationChannels();
    }

    public function test_request_correct_serial_places_case_in_waiting_customer_queue(): void
    {
        [$agent, $incident] = $this->createInvalidSerialIncident();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-correct-serial-waiting'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-correct-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk()
            ->assertJsonPath('success', true);

        app(DashboardSnapshotStore::class)->forget();

        $freshIncident = $this->freshIncident($incident);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($freshIncident));
        $this->assertSame(OperationQueue::WaitingCustomer, $classifier->classify($freshIncident));
    }

    public function test_admin_serial_correction_clears_waiting_and_enters_ready_queue(): void
    {
        $admin = $this->adminUser();
        [$agent, $incident] = $this->createInvalidSerialIncident($admin);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-correct-serial-ready'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-correct-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $order = $incident->order;
        $order->update([
            'missing_serial_automation_status' => MissingSerialAutomationStatus::Completed->value,
        ]);
        $this->markSynced($order);

        app(DashboardSnapshotStore::class)->forget();

        $freshBefore = $this->freshIncident($incident);
        $this->assertTrue(app(OperationsQueueClassifier::class)->isWaitingCustomer($freshBefore));

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => $order->order_id,
                'serial_number' => '7881953',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertRedirect(route('orders.show', $order));

        app(DashboardSnapshotStore::class)->forget();

        $freshIncident = $this->freshIncident($incident);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertNull($freshIncident->activeWaitingState);
        $this->assertNotNull(IncidentWaitingState::query()->where('incident_id', $incident->id)->value('cleared_at'));
        $this->assertFalse($classifier->isWaitingCustomer($freshIncident));
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($freshIncident));

        $this->assertDatabaseHas('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_IDENTITY_RESOLVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_admin_device_model_correction_clears_identity_waiting_and_enters_ready_queue(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-MODEL-WAITING-CLEAR',
            'serial_number' => '7881953',
            'product_name' => null,
            'device_model' => null,
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $admin, [
            'serial_correction' => false,
        ]);

        app(DashboardSnapshotStore::class)->forget();

        $this->assertTrue(app(OperationsQueueClassifier::class)->isWaitingCustomer($this->freshIncident($incident)));

        app(OrderDeviceModelService::class)->assignDeviceModel($order, $deviceModel, $admin);

        app(DashboardSnapshotStore::class)->forget();

        $freshIncident = $this->freshIncident($incident);

        $this->assertNotNull(IncidentWaitingState::query()->where('incident_id', $incident->id)->value('cleared_at'));
        $this->assertFalse(app(OperationsQueueClassifier::class)->isWaitingCustomer($freshIncident));
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($freshIncident));
    }

    public function test_payment_waiting_is_not_cleared_by_identity_correction(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-PAYMENT-WAITING',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::Payment,
            actor: $admin,
        );

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => $order->order_id,
                'serial_number' => '7881953',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertRedirect(route('orders.show', $order));

        $waitingState = IncidentWaitingState::query()->where('incident_id', $incident->id)->first();

        $this->assertNotNull($waitingState);
        $this->assertNull($waitingState->cleared_at);
        $this->assertSame(WaitingReason::Payment, $waitingState->waiting_reason);
        $this->assertTrue(app(OperationsQueueClassifier::class)->isWaitingCustomer($this->freshIncident($incident)));
    }

    public function test_customer_not_responding_waiting_is_not_cleared_by_identity_correction(): void
    {
        $admin = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => 'RD-CALLBACK-WAITING',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(IncidentWaitingStateService::class)->ensureCustomerNotRespondingWaitingState($incident, $admin);

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => $order->order_id,
                'serial_number' => '7881953',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertRedirect(route('orders.show', $order));

        $waitingState = IncidentWaitingState::query()->where('incident_id', $incident->id)->first();

        $this->assertNotNull($waitingState);
        $this->assertNull($waitingState->cleared_at);
        $this->assertSame(WaitingReason::CustomerNotResponding, $waitingState->waiting_reason);
    }

    public function test_identity_waiting_clear_is_idempotent_when_lifecycle_runs_twice(): void
    {
        $admin = $this->adminUser();
        $order = Order::query()->create([
            'order_id' => 'RD-LIFECYCLE-IDEMPOTENT',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'missing_serial_automation_status' => MissingSerialAutomationStatus::Completed->value,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $admin, [
            'serial_correction' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('orders.update', $order), [
                'order_id' => $order->order_id,
                'serial_number' => '7881953',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])
            ->assertRedirect(route('orders.show', $order));

        $resolvedAuditCount = AuditLog::query()
            ->where('event', CustomerWaitingLifecycleService::EVENT_IDENTITY_RESOLVED)
            ->where('auditable_id', $incident->id)
            ->count();

        $this->assertSame(1, $resolvedAuditCount);

        app(OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $order->fresh(),
            actor: $admin,
            source: 'order_admin_edit',
            serialChanged: false,
        );

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('event', CustomerWaitingLifecycleService::EVENT_IDENTITY_RESOLVED)
                ->where('auditable_id', $incident->id)
                ->count(),
        );
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createInvalidSerialIncident(?User $assignee = null): array
    {
        $agent = $assignee ?? tap(User::factory()->create(), function (User $user): void {
            $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        });

        $order = Order::query()->create([
            'order_id' => 'RD-IDENTITY-WAITING-'.uniqid(),
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Invalid Serial Customer',
            'customer_email' => 'invalid@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $agent, assignee: $agent);

        return [$agent, $incident];
    }

    private function createIncident(Order $order, User $creator, ?User $assignee = null): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Identity waiting test',
            'description' => 'Identity waiting test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }

    private function freshIncident(Incident $incident): Incident
    {
        return $incident->fresh([
            'order',
            'assignee.roles',
            'activeWaitingState',
            'supportAppointments',
        ]);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function markSynced(Order $order): void
    {
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id, [
            'lookup_result' => 'data_received',
        ]);

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id));
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
