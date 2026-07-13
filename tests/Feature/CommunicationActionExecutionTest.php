<?php

namespace Tests\Feature;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
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

class CommunicationActionExecutionTest extends TestCase
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
            'interakt.templates.review_request.name' => 'review_request_template',
            'interakt.templates.review_request.display_name' => 'Review Request',
            'interakt.templates.review_request.language_code' => 'en',
            'interakt.templates.review_request.enabled' => true,
        ]);
    }

    public function test_agent_can_execute_review_request_and_records_audit_trail(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-review-001'], 200),
        ]);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::ReviewRequest->value,
            ]), [
                'workspace_context' => 'customer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_logs', [
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
        ]);

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('review_request', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame('Review request sent', $dispatchAudit->new_values['communication_action_label']);
    }

    public function test_opening_communication_action_dialog_records_opened_lifecycle_event(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'communication-action',
            ]).'?workspace_context=customer&key='.CommunicationActionKey::ReviewRequest->value)
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => CommunicationActionLifecycleAuditService::EVENT,
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'user_id' => $agent->id,
        ]);

        $lifecycleAudit = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->first();

        $this->assertSame('opened', $lifecycleAudit->new_values['status']);
        $this->assertSame('review_request', $lifecycleAudit->new_values['action_key']);
    }

    public function test_successful_execution_records_sent_and_completed_lifecycle_events(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => false,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-review-002'], 200),
        ]);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::ReviewRequest->value,
            ]), [
                'workspace_context' => 'customer',
            ])
            ->assertOk();

        $statuses = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $auditLog): string => (string) ($auditLog->new_values['status'] ?? ''))
            ->all();

        $this->assertSame(['sent', 'completed'], $statuses);
    }

    public function test_agent_cannot_execute_refund_confirmation(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

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

    public function test_communication_action_component_requires_eligible_action_key(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'communication-action',
            ]).'?workspace_context=customer&key='.CommunicationActionKey::RefundConfirmation->value)
            ->assertForbidden();
    }

    public function test_customer360_drawer_lists_only_eligible_communication_actions_for_agent(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('💬', false);
        $response->assertSee('>Communication<', false);
        $response->assertSee('Review Request');
        $response->assertSee('data-customer-360-section="communication-actions"', false);
        $response->assertSee('Communication Actions');
        $response->assertSee('data-communication-action-key="refund_confirmation"', false);
        $response->assertSee('You do not have permission to run this communication action.', false);
        $response->assertDontSee('data-workspace-communication-action-key="refund_confirmation"', false);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(User $actor): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-EXEC',
            'serial_number' => 'SN-EXEC',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
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
            'title' => 'Communication action execution case',
            'description' => 'Communication action execution case.',
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
