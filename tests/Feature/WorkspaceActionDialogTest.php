<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\ServiceCaseCloseException;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceActionDialogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'service_case_assignment.escalation.level_1_email' => 'shubhanshi@radiumbox.com',
        ]);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createEscalationSpecialist(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        return $user;
    }

    private function createIncident(User $creator, array $overrides = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-ACTION-1',
            'serial_number' => $overrides['serial_number'] ?? 'SN-ACTION-1',
            'product_name' => $overrides['product_name'] ?? 'MFS 110',
            'device_model' => $overrides['device_model'] ?? 'MFS 110',
            'cashfree_payment_id' => $overrides['cashfree_payment_id'] ?? null,
            'transaction_id' => $overrides['transaction_id'] ?? null,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        unset(
            $overrides['order_id'],
            $overrides['serial_number'],
            $overrides['transaction_id'],
            $overrides['product_name'],
            $overrides['device_model'],
            $overrides['cashfree_payment_id'],
        );

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => $overrides['title'] ?? 'Action dialog test',
            'description' => $overrides['description'] ?? 'Action dialog test description.',
            'status' => $overrides['status'] ?? IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_action_component_fragment_renders_manage_case_dialog(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Support Agent');
        $this->createEscalationSpecialist('shubhanshi@radiumbox.com', 'Shubhanshi');
        $incident = $this->createIncident($admin, ['assigned_to_user_id' => $agent->id]);
        $incident->load('order');

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'action',
                'context' => WorkspaceContext::ServiceCase->value,
            ]))
            ->assertOk()
            ->assertSee('Manage Case', false)
            ->assertSee($incident->display_reference.' • '.$incident->order->order_id, false)
            ->assertSee('data-workspace-action-form="action"', false)
            ->assertSee('data-workspace-action-card="assign"', false)
            ->assertSee('data-workspace-action-card="close"', false)
            ->assertSee('data-workspace-action-card="escalate"', false)
            ->assertSee('workspace-action-segments', false)
            ->assertSee('Transfer ownership to another engineer.', false)
            ->assertSee('The assigned engineer will be notified.', false)
            ->assertSee('Notify Customer', false)
            ->assertSee('data-workspace-action-remark', false)
            ->assertSee('data-mention-textarea', false)
            ->assertSee('workspace-action-submit--assign', false)
            ->assertSee('Assign Engineer', false)
            ->assertSee('data-workspace-action-submit', false)
            ->assertSee(route('incidents.workspace.action', $incident), false);
    }

    public function test_assign_action_requires_remark_and_assignee(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Assign->value,
                'assigned_to_user_id' => $shipra->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('action', 'action')
            ->assertJsonStructure(['errors' => ['body']]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Assign->value,
                'assigned_to_user_id' => $shipra->id,
                'body' => 'Assigning to Shipra for follow-up.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'action');

        $this->assertSame($shipra->id, $incident->fresh()->assigned_to_user_id);
        $this->assertDatabaseHas('remarks', [
            'remarkable_id' => $incident->id,
            'body' => 'Assigning to Shipra for follow-up.',
        ]);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $this->assertStringContainsString('Assigning to Shipra for follow-up.', $timelineHtml);
    }

    public function test_close_action_closes_service_case_with_remark_and_timeline(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::InProgress]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Customer confirmed resolution.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $this->assertStringContainsString('Service case closed', $timelineHtml);
        $this->assertStringContainsString('Customer confirmed resolution.', $timelineHtml);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => 'service_case.status_changed',
        ]);
    }

    public function test_close_action_with_exceptions_creates_exs_exr_ids_and_applies_values(): void
    {
        $this->travelTo('2026-07-01 12:00:00');

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, [
            'reference_no' => '',
            'serial_number' => null,
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Closing with documented exceptions.',
                'serial_number_unavailable' => true,
                'reference_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::CustomerCancelledBeforePayment->value,
                'reference_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
            ])
            ->assertOk();

        $exceptions = ServiceCaseCloseException::query()
            ->where('incident_id', $incident->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $exceptions);
        $this->assertSame('EXS-20260701-0001', $exceptions[0]->exception_id);
        $this->assertSame('EXR-20260701-0001', $exceptions[1]->exception_id);
        $this->assertTrue($exceptions[0]->serial_number_unavailable);
        $this->assertTrue($exceptions[1]->reference_number_unavailable);

        $freshIncident = $incident->fresh(['order']);
        $this->assertSame('EXS-20260701-0001', $freshIncident->order?->serial_number);
        $this->assertSame('EXR-20260701-0001', $freshIncident->reference_no);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $this->assertStringContainsString('EXS-20260701-0001', $timelineHtml);
        $this->assertStringContainsString('EXR-20260701-0001', $timelineHtml);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $incident->id,
            'event' => 'service_case.close_exception',
        ]);
    }

    public function test_exception_id_generation_is_unique_per_type_and_day(): void
    {
        $this->travelTo('2026-07-01 12:00:00');

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $first = $this->createIncident($admin, ['order_id' => 'ORD-EX-1', 'reference_no' => 'REF-EX-1']);
        $second = $this->createIncident($admin, ['order_id' => 'ORD-EX-2', 'reference_no' => '']);
        $third = $this->createIncident($admin, ['order_id' => 'ORD-EX-3', 'reference_no' => 'REF-EX-3']);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $first), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Serial exception close.',
                'serial_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $second), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Reference exception close.',
                'reference_number_unavailable' => true,
                'reference_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $third), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Both exceptions close.',
                'serial_number_unavailable' => true,
                'reference_number_unavailable' => true,
                'serial_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
                'reference_exception_reason' => ServiceCaseCloseExceptionReason::ApprovedByAdmin->value,
            ])
            ->assertOk();

        $serialIds = ServiceCaseCloseException::query()
            ->where('exception_id', 'like', 'EXS-%')
            ->orderBy('id')
            ->pluck('exception_id')
            ->all();
        $referenceIds = ServiceCaseCloseException::query()
            ->where('exception_id', 'like', 'EXR-%')
            ->orderBy('id')
            ->pluck('exception_id')
            ->all();

        $this->assertSame(['EXS-20260701-0001', 'EXS-20260701-0002'], $serialIds);
        $this->assertSame(['EXR-20260701-0001', 'EXR-20260701-0002'], $referenceIds);
    }

    public function test_close_validation_returns_first_error_message_in_dialog_response(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['reference_no' => '']);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Attempting close without reference.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Reference Number is required before closing this service case.')
            ->assertJsonPath('toast.message', 'Reference Number is required before closing this service case.')
            ->assertJsonPath('refresh.fragments.0.component', 'action');

        $this->assertStringNotContainsString(
            'Please correct the highlighted fields.',
            (string) $response->json('toast.message'),
        );
    }

    public function test_inquiry_case_can_close_without_serial_number(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, [
            'order_id' => 'INQ-SC08777',
            'serial_number' => '',
        ])->load('order');

        $this->assertTrue($incident->order?->isInquiryOrder());
        $this->assertSame('', (string) $incident->order?->serial_number);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Closing inquiry without serial.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertTrue(! filled(trim((string) $incident->fresh()->order?->serial_number)));
    }

    public function test_device_case_still_requires_serial_number_before_close(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, [
            'order_id' => 'RD-DEVICE-1',
            'serial_number' => '',
        ])->load('order');

        $this->assertFalse($incident->order?->isInquiryOrder());

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Attempting close without serial.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Serial Number is required before closing this service case.');

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_inquiry_case_does_not_show_request_serial_number_action(): void
    {
        config(['interakt.templates.request_serial_number.name' => 'order_update_request_serial']);

        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, [
            'order_id' => 'INQ-SC08777',
            'serial_number' => '',
        ])->load('order');

        $incident->order->update(['customer_phone' => '9123456780']);

        $this->assertTrue($incident->order->isInquiryOrder());

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertFalse(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
        $this->assertStringNotContainsString('Request Serial Number', $html);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertForbidden();
    }

    public function test_device_case_missing_serial_still_shows_request_serial_number_action(): void
    {
        config(['interakt.templates.request_serial_number.name' => 'order_update_request_serial']);

        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, [
            'order_id' => 'RD-DEVICE-1',
            'serial_number' => '',
        ])->load('order');

        $incident->order->update(['customer_phone' => '9123456780']);

        $this->assertFalse($incident->order->isInquiryOrder());
        $this->assertTrue(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-workspace-trigger="request-serial"', false)
            ->assertSee('Request Serial Number', false);
    }

    public function test_reopen_dialog_does_not_show_assignee_selector(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::Closed]);

        $this->actingAs($admin)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'action',
                'context' => WorkspaceContext::ServiceCase->value,
            ]))
            ->assertOk()
            ->assertSee('data-workspace-action-card="reopen"', false)
            ->assertDontSee('workspace_action_reopen_assignee', false)
            ->assertDontSee('data-workspace-action-panel="reopen"', false);
    }

    public function test_reopen_action_reopens_closed_service_case_with_remark_only(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $shipra = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $incident = $this->createIncident($admin, ['status' => IncidentStatus::Closed]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Reopen->value,
                'assigned_to_user_id' => $shipra->id,
                'body' => 'Reopening for further investigation.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame($shipra->id, $incident->fresh()->assigned_to_user_id);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $this->assertStringContainsString('Case reopened by', $timelineHtml);
        $this->assertStringContainsString('Assigned to', $timelineHtml);
    }

    public function test_reopen_action_assigns_to_reopening_user_when_no_assignee_specified(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $previousAssignee = $this->createAdminUser('old@example.com', 'Old Assignee');
        $incident = $this->createIncident($admin, [
            'status' => IncidentStatus::Closed,
            'assigned_to_user_id' => $previousAssignee->id,
        ]);

        $response = $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Reopen->value,
                'body' => 'Reopening to take ownership.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'user_id' => $admin->id,
        ]);

        $timelineHtml = collect($response->json('refresh.targets'))
            ->firstWhere('selector', '#activity-timeline')['html'] ?? '';
        $this->assertStringContainsString('Case reopened by', $timelineHtml);
        $this->assertStringContainsString('Assigned to Admin', $timelineHtml);
    }

    public function test_global_search_finds_service_case_by_exception_id(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, ['reference_no' => '']);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Closed with exception.',
                'reference_number_unavailable' => true,
                'reference_exception_reason' => ServiceCaseCloseExceptionReason::DuplicateServiceCase->value,
            ])
            ->assertOk();

        $exceptionId = ServiceCaseCloseException::query()->value('exception_id');

        $this->actingAs($admin)
            ->getJson(route('search.index', ['q' => $exceptionId]))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    public function test_legacy_resolve_route_now_closes_service_case(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin);

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.resolve', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Legacy resolve route.',
            ])
            ->assertOk()
            ->assertJsonPath('action', 'action');

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_dashboard_rows_open_customer360_without_actions_column(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->createIncident($agent, ['assigned_to_user_id' => $agent->id]);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-case-row--clickable', false)
            ->assertDontSee('data-c360-open-more-menu', false)
            ->assertDontSee('data-workspace-trigger="resolve"', false)
            ->assertDontSee('data-workspace-trigger="close"', false);
    }

    public function test_agent_close_requires_transaction_id(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent);

        Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Prior remark.',
        ]);

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'action_type' => WorkspaceActionType::Close->value,
                'body' => 'Ready to close.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['transaction_id']]);
    }

    public function test_remote_support_case_can_close_without_serial_device_model_or_transaction_id(): void
    {
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $incident = $this->createIncident($agent, [
            'order_id' => 'CFPay_techsupport_test_002',
            'serial_number' => '10137886',
            'cashfree_payment_id' => 'cf_pay_remote_support_002',
            'product_name' => null,
            'device_model' => null,
            'transaction_id' => null,
        ])->load('order');

        $this->assertTrue($incident->order?->isRemoteSupportOrder());

        $this->actingAs($agent)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => 'issue_resolved',
                'body' => 'Remote support session completed.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_hardware_case_still_requires_serial_verification_before_close(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $incident = $this->createIncident($admin, [
            'order_id' => 'RD-DEVICE-UNSUPPORTED',
            'serial_number' => '10137886',
            'product_name' => null,
            'device_model' => null,
            'transaction_id' => 'TXN-123',
        ])->load('order');

        $this->assertFalse($incident->order?->isRemoteSupportOrder());

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.action', $incident), [
                'workspace_context' => WorkspaceContext::ServiceCase->value,
                'action_type' => WorkspaceActionType::Close->value,
                'reason_for_closing' => 'issue_resolved',
                'body' => 'Attempting hardware close with unverified serial.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Serial number must be verified or corrected before closing this service case.');

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }
}
