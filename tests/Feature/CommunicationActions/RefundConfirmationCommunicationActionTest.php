<?php

namespace Tests\Feature\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class RefundConfirmationCommunicationActionTest extends TestCase
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
            'interakt.templates.refund_confirmation.name' => 'refund_confirmation_template',
            'interakt.templates.refund_confirmation.display_name' => 'Refund Confirmation',
            'interakt.templates.refund_confirmation.language_code' => 'en',
            'interakt.templates.refund_confirmation.enabled' => true,
        ]);
    }

    public function test_admin_can_execute_refund_confirmation_and_records_audit_trail(): void
    {
        $admin = User::factory()->create(['name' => 'Refund Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);
        $this->createApprovedRefund($incident, $admin);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-refund-001'], 200),
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::RefundConfirmation->value,
            ]), [
                'workspace_context' => 'customer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('refund_confirmation', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame('Refund confirmation sent', $dispatchAudit->new_values['communication_action_label']);

        $statuses = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $auditLog): string => (string) ($auditLog->new_values['status'] ?? ''))
            ->all();

        $this->assertSame(['sent', 'completed'], $statuses);
    }

    public function test_customer360_lists_refund_confirmation_when_eligible_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);
        $this->createApprovedRefund($incident, $admin);

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Send Refund Confirmation')
            ->assertSee('data-workspace-communication-action-key="refund_confirmation"', false)
            ->assertSee('data-communication-action-status="available"', false);
    }

    public function test_agent_cannot_execute_refund_confirmation(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($agent);
        $this->createApprovedRefund($incident, $admin);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::RefundConfirmation->value,
            ]), [
                'workspace_context' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_refund_confirmation_is_unavailable_without_approved_refund(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);

        $this->actingAs($admin)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::RefundConfirmation->value,
            ]), [
                'workspace_context' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_opening_refund_confirmation_dialog_records_opened_lifecycle_event(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);
        $this->createApprovedRefund($incident, $admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'communication-action',
            ]).'?workspace_context=customer&key='.CommunicationActionKey::RefundConfirmation->value)
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
    private function createIncident(User $actor): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-REFUND-EXEC',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Refund Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Refund confirmation execution case',
            'description' => 'Refund confirmation execution case.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }

    private function createApprovedRefund(Incident $incident, User $actor): RefundRequest
    {
        return RefundRequest::query()->create([
            'order_id' => $incident->order_id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000500',
            'amount' => 3200,
            'reason' => 'Approved refund for communication execution.',
            'status' => RefundStatus::Approved,
            'requested_by' => $actor->id,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-500',
        ]);
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
