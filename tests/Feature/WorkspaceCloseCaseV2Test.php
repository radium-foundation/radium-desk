<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceCloseCaseV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin-close-v2@example.com',
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createIncident(User $creator, array $overrides = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-CLOSE-V2-1',
            'serial_number' => $overrides['serial_number'] ?? 'SN-CLOSE-V2-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => $overrides['transaction_id'] ?? 'TXN-CLOSE-V2',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        unset($overrides['order_id'], $overrides['serial_number'], $overrides['transaction_id']);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Close v2 test',
            'description' => 'Close v2 test description.',
            'status' => $overrides['status'] ?? IncidentStatus::InProgress,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_close_v2_ui_renders_reason_for_closing_fields(): void
    {
        $admin = $this->createAdminUser();
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'action',
                'context' => WorkspaceContext::ServiceCase->value,
                'action' => WorkspaceActionType::Close->value,
            ]))
            ->assertOk()
            ->assertSee('Reason for Closing', false)
            ->assertSee('Resolution Type', false)
            ->assertSee('Notify Customer', false)
            ->assertSee('Closing Summary', false)
            ->assertDontSee('Exceptions', false);
    }

    public function test_close_v2_issue_resolved_stores_outcome_and_closes_case(): void
    {
        $admin = $this->createAdminUser();
        $incident = $this->createIncident($admin);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::IssueResolved->value,
                'resolution_type' => ServiceCaseCloseResolutionType::DeviceWorking->value,
                'notification_preference' => ServiceCaseCloseNotificationPreference::WhatsApp->value,
                'body' => 'Device confirmed working after driver install.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);

        $this->assertDatabaseHas('service_case_close_outcomes', [
            'incident_id' => $incident->id,
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::IssueResolved->value,
            'resolution_type' => ServiceCaseCloseResolutionType::DeviceWorking->value,
            'closing_summary' => 'Device confirmed working after driver install.',
            'notification_preference' => ServiceCaseCloseNotificationPreference::WhatsApp->value,
            'closed_by' => $admin->id,
        ]);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';

        $this->assertStringContainsString('Reason for Closing', $timelineHtml);
        $this->assertStringContainsString('Issue Resolved', $timelineHtml);
        $this->assertStringContainsString('Resolution Type', $timelineHtml);
        $this->assertStringContainsString('Device Working', $timelineHtml);
        $this->assertStringContainsString('Closing Summary', $timelineHtml);
    }

    public function test_close_v2_reference_number_pending_creates_legacy_exception(): void
    {
        $this->travelTo('2026-07-14 12:00:00');

        $admin = $this->createAdminUser();
        $incident = $this->createIncident($admin, ['reference_no' => '']);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::ReferenceNumberPending->value,
                'expected_from' => 'customer',
                'expected_date' => '2026-07-20',
                'body' => 'Waiting for customer to share reference number.',
            ])
            ->assertOk();

        $freshIncident = $incident->fresh();
        $this->assertSame('EXR-20260714-0001', $freshIncident->reference_no);

        $outcome = ServiceCaseCloseOutcome::query()->where('incident_id', $incident->id)->first();
        $this->assertNotNull($outcome);
        $this->assertSame('customer', $outcome->metadata['expected_from']);
        $this->assertSame('2026-07-20', $outcome->metadata['expected_date']);
    }

    public function test_close_v2_duplicate_case_requires_existing_case_id(): void
    {
        $admin = $this->createAdminUser();
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::DuplicateCase->value,
                'body' => 'Duplicate of another open case.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['existing_case_id']]);
    }

    public function test_legacy_close_payload_without_reason_for_closing_still_works(): void
    {
        $admin = $this->createAdminUser();
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Legacy close without v2 fields.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertDatabaseMissing('service_case_close_outcomes', [
            'incident_id' => $incident->id,
        ]);
    }
}
