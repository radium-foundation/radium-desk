<?php

namespace Tests\Feature\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use App\Support\AppDateFormatter;
use Tests\TestCase;

class ReviewRequestCommunicationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
        $agent = User::factory()->create(['name' => 'Review Agent']);
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

        $dispatchAudit = AuditLog::query()
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->first();

        $this->assertSame('review_request', $dispatchAudit->new_values['communication_action_key']);
        $this->assertSame('Review request sent', $dispatchAudit->new_values['communication_action_label']);

        $statuses = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $auditLog): string => (string) ($auditLog->new_values['status'] ?? ''))
            ->all();

        $this->assertSame(['sent', 'completed'], $statuses);
    }

    public function test_customer360_lists_review_request_when_eligible(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('Review Request');
        $response->assertSee('data-workspace-communication-action-key="review_request"', false);
    }

    public function test_review_request_is_unavailable_before_support_is_concluded(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::InProgress);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => CommunicationActionKey::ReviewRequest->value,
            ]), [
                'workspace_context' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_customer360_shows_sent_status_after_review_request_execution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 14:00:00', AppDateFormatter::timezone()));

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        app(CommunicationActionLifecycleService::class)->recordSuccessfulExecution(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::ReviewRequest->value,
            channels: ['whatsapp'],
        );

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-communication-action-key="review_request"', false)
            ->assertSee('data-communication-action-status="sent"', false)
            ->assertSee('Sent today', false);
    }

    public function test_opening_review_request_dialog_records_opened_lifecycle_event(): void
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
        ]);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(
        User $actor,
        IncidentStatus $status = IncidentStatus::Resolved,
    ): array {
        $order = Order::query()->create([
            'order_id' => 'RD-REVIEW-EXEC',
            'serial_number' => '7881954',
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
            'title' => 'Review request execution case',
            'description' => 'Review request execution case.',
            'status' => $status,
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
