<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\DashboardService;
use App\Services\OrderTransactionService;
use App\Services\WorkspaceRefreshRenderer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class WorkspaceBatchTransactionActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'Batch Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    /**
     * @return array{order: Order, incident: Incident}
     */
    private function createPendingCase(User $creator, string $suffix): array
    {
        $order = Order::query()->create([
            'order_id' => "RD-BATCH-WS-{$suffix}",
            'serial_number' => "SN-BATCH-WS-{$suffix}",
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => "cf_batch_ws_{$suffix}",
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => "SC-BATCH-WS-{$suffix}",
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Batch workspace {$suffix}",
            'description' => "Batch workspace {$suffix}.",
            'status' => IncidentStatus::Open->value,
            'created_by' => $creator->id,
        ]);

        return compact('order', 'incident');
    }

    public function test_admin_can_load_batch_transaction_component(): void
    {
        $admin = $this->createAdmin();
        $first = $this->createPendingCase($admin, '1');
        $second = $this->createPendingCase($admin, '2');

        $this->actingAs($admin)
            ->get(route('dashboard.components.batch-transaction', [
                'incident_ids' => [$first['incident']->id, $second['incident']->id],
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertOk()
            ->assertSee('Assign Service Reference', false)
            ->assertSee('Selected Orders:', false)
            ->assertSee('Serial Numbers', false)
            ->assertSee('data-copy-all-serials', false)
            ->assertSee($first['order']->serial_number, false)
            ->assertSee($second['order']->serial_number, false)
            ->assertSee('data-batch-serial-copy', false)
            ->assertSee('data-workspace-action-form="batch-transaction"', false);
    }

    public function test_batch_success_returns_refresh_payload_and_closes_modal(): void
    {
        $admin = $this->createAdmin();
        $sharedOrder = Order::query()->create([
            'order_id' => 'RD-BATCH-WS-SHARED',
            'serial_number' => 'SN-BATCH-WS-SHARED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_batch_ws_shared',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $firstIncident = Incident::query()->create([
            'order_id' => $sharedOrder->id,
            'reference_no' => 'SC-BATCH-WS-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Batch workspace 1',
            'description' => 'Batch workspace 1.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $secondIncident = Incident::query()->create([
            'order_id' => $sharedOrder->id,
            'reference_no' => 'SC-BATCH-WS-2',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Batch workspace 2',
            'description' => 'Batch workspace 2.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$firstIncident->id, $secondIncident->id],
                'transaction_id' => 'TX-BATCH-WS',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('action', 'batch-transaction')
            ->assertJsonPath('success', true)
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('refresh.kpis', true)
            ->assertJsonStructure([
                'refresh' => [
                    'remove_rows' => [
                        ['incident_id'],
                    ],
                    'kpis_html' => ['kpi_strip_html'],
                ],
                'extensions' => [
                    'succeeded_incident_ids',
                    'failed_incidents',
                ],
            ]);

        $this->assertSame('TX-BATCH-WS', $sharedOrder->fresh()->transaction_id);
        $this->assertCount(2, $response->json('extensions.succeeded_incident_ids'));
        $this->assertCount(2, $response->json('refresh.remove_rows'));
        $this->assertSame([], $response->json('refresh.replace_rows'));
    }

    public function test_batch_partial_failure_keeps_modal_open_and_reports_failed_rows(): void
    {
        $admin = $this->createAdmin();
        $pending = $this->createPendingCase($admin, 'OK');

        $lockedOrder = Order::query()->create([
            'order_id' => 'RD-BATCH-WS-LOCKED',
            'serial_number' => 'SN-BATCH-WS-LOCKED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX-OLD',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $lockedIncident = Incident::query()->create([
            'order_id' => $lockedOrder->id,
            'reference_no' => 'SC-BATCH-WS-LOCKED',
            'category' => 'General',
            'source' => IncidentSource::Email,
            'title' => 'Locked case',
            'description' => 'Locked case.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$pending['incident']->id, $lockedIncident->id],
                'transaction_id' => 'TX-BATCH-PARTIAL',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonPath('extensions.succeeded_incident_ids.0', $pending['incident']->id);

        $this->assertCount(1, $response->json('extensions.failed_incidents'));
        $this->assertSame('TX-BATCH-PARTIAL', $pending['order']->fresh()->transaction_id);
        $this->assertSame('TX-OLD', $lockedOrder->fresh()->transaction_id);
    }

    public function test_batch_validation_requires_transaction_id_and_selected_orders(): void
    {
        $admin = $this->createAdmin();
        $pending = $this->createPendingCase($admin, 'VAL');

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$pending['incident']->id],
                'transaction_id' => '',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('ui.close_workspace_host', false)
            ->assertJsonStructure([
                'errors' => ['transaction_id'],
                'refresh' => ['fragments'],
            ]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [],
                'transaction_id' => 'TX-123',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['incident_ids']]);
    }

    public function test_agent_cannot_use_batch_transaction_workspace_action(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $pending = $this->createPendingCase($agent, 'AGENT');

        $this->actingAs($agent)
            ->get(route('dashboard.components.batch-transaction', [
                'incident_ids' => [$pending['incident']->id],
                'context' => WorkspaceContext::Dashboard->value,
            ]))
            ->assertForbidden();

        $this->actingAs($agent)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$pending['incident']->id],
                'transaction_id' => 'TX-123',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertForbidden();
    }

    public function test_batch_selected_orders_can_share_service_reference(): void
    {
        $admin = $this->createAdmin();
        $first = $this->createPendingCase($admin, 'SHARE-1');
        $second = $this->createPendingCase($admin, 'SHARE-2');
        $third = $this->createPendingCase($admin, 'SHARE-3');

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [
                    $first['incident']->id,
                    $second['incident']->id,
                    $third['incident']->id,
                ],
                'transaction_id' => 'TX-BATCH-SHARED',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ui.close_workspace_host', true);

        $this->assertCount(3, $response->json('extensions.succeeded_incident_ids'));
        $this->assertSame('TX-BATCH-SHARED', $first['order']->fresh()->transaction_id);
        $this->assertSame('TX-BATCH-SHARED', $second['order']->fresh()->transaction_id);
        $this->assertSame('TX-BATCH-SHARED', $third['order']->fresh()->transaction_id);
    }

    public function test_batch_allows_later_unrelated_service_reference_reuse(): void
    {
        $admin = $this->createAdmin();
        $first = $this->createPendingCase($admin, 'REUSE-1');
        $second = $this->createPendingCase($admin, 'REUSE-2');

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$first['incident']->id, $second['incident']->id],
                'transaction_id' => 'TX-BATCH-REUSE',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $unrelated = $this->createPendingCase($admin, 'REUSE-9');

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$unrelated['incident']->id],
                'transaction_id' => 'TX-BATCH-REUSE',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ui.close_workspace_host', true);

        $this->assertSame('TX-BATCH-REUSE', $unrelated['order']->fresh()->transaction_id);
    }

    public function test_bulk_assign_continues_when_order_34_of_35_throws(): void
    {
        Queue::fake();
        Notification::fake();

        $admin = $this->createAdmin();
        $incidents = [];

        for ($index = 1; $index <= 35; $index++) {
            $incidents[] = $this->createPendingCase($admin, (string) $index)['incident'];
        }

        $beginKpiCoalesceCalls = 0;

        $this->partialMock(DashboardBroadcastService::class, function ($mock) use (&$beginKpiCoalesceCalls): void {
            $mock->shouldReceive('beginKpiCoalesce')->andReturnUsing(function () use (&$beginKpiCoalesceCalls): void {
                $beginKpiCoalesceCalls++;

                if ($beginKpiCoalesceCalls === 34) {
                    throw new RuntimeException('Simulated broadcast failure on order 34');
                }
            });
        });

        $incidentIds = array_map(fn (Incident $incident): int => $incident->id, $incidents);

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => $incidentIds,
                'transaction_id' => 'TX-BATCH-HOTFIX',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('ui.close_workspace_host', false);

        $succeededIncidentIds = $response->json('extensions.succeeded_incident_ids');
        $failedIncidents = $response->json('extensions.failed_incidents');

        $this->assertCount(34, $succeededIncidentIds);
        $this->assertCount(1, $failedIncidents);
        $this->assertSame($incidents[33]->id, $failedIncidents[0]['incident_id']);
        $this->assertSame('Simulated broadcast failure on order 34', $failedIncidents[0]['message']);
        $this->assertContains($incidents[34]->id, $succeededIncidentIds);

        $this->assertNull(Order::query()->find($incidents[33]->order_id)?->transaction_id);
        $this->assertSame('TX-BATCH-HOTFIX', Order::query()->find($incidents[34]->order_id)?->transaction_id);
    }

    public function test_bulk_assign_returns_success_when_transactions_assigned_broadcast_throws(): void
    {
        Queue::fake();
        Notification::fake();

        $admin = $this->createAdmin();
        $first = $this->createPendingCase($admin, 'BCAST-1');
        $second = $this->createPendingCase($admin, 'BCAST-2');

        $this->partialMock(DashboardBroadcastService::class, function ($mock): void {
            $mock->shouldReceive('transactionsAssigned')
                ->andThrow(new RuntimeException('Simulated transactionsAssigned broadcast failure'));
        });

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$first['incident']->id, $second['incident']->id],
                'transaction_id' => 'TX-BATCH-BCAST',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.warning', OrderTransactionService::DASHBOARD_REFRESH_WARNING)
            ->assertJsonMissing(['message' => 'Something went wrong on the server. Please try again.']);

        $this->assertCount(2, $response->json('extensions.succeeded_incident_ids'));
        $this->assertSame('TX-BATCH-BCAST', $first['order']->fresh()->transaction_id);
        $this->assertSame('TX-BATCH-BCAST', $second['order']->fresh()->transaction_id);
    }

    public function test_bulk_assign_returns_success_when_workspace_refresh_renderer_throws(): void
    {
        Queue::fake();
        Notification::fake();

        $admin = $this->createAdmin();
        $first = $this->createPendingCase($admin, 'REFRESH-1');
        $second = $this->createPendingCase($admin, 'REFRESH-2');

        $this->partialMock(WorkspaceRefreshRenderer::class, function ($mock): void {
            $mock->shouldReceive('buildBatchRefreshPayload')
                ->andThrow(new RuntimeException('Simulated workspace refresh render failure'));
        });

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$first['incident']->id, $second['incident']->id],
                'transaction_id' => 'TX-BATCH-REFRESH',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.warning', OrderTransactionService::DASHBOARD_REFRESH_WARNING)
            ->assertJsonPath('refresh.replace_rows', [])
            ->assertJsonCount(2, 'refresh.remove_rows');

        $this->assertCount(2, $response->json('extensions.succeeded_incident_ids'));
        $this->assertSame('TX-BATCH-REFRESH', $first['order']->fresh()->transaction_id);
        $this->assertSame('TX-BATCH-REFRESH', $second['order']->fresh()->transaction_id);
    }

    public function test_bulk_assign_returns_success_when_replace_rows_rendering_throws(): void
    {
        Queue::fake();
        Notification::fake();

        $admin = $this->createAdmin();
        $first = $this->createPendingCase($admin, 'ROWS-1');
        $second = $this->createPendingCase($admin, 'ROWS-2');

        $this->partialMock(DashboardService::class, function ($mock): void {
            $mock->shouldReceive('serviceCaseRowViewData')
                ->andThrow(new RuntimeException('Simulated replace_rows render failure'));
        });

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$first['incident']->id, $second['incident']->id],
                'transaction_id' => 'TX-BATCH-ROWS',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.warning', OrderTransactionService::DASHBOARD_REFRESH_WARNING)
            ->assertJsonPath('refresh.replace_rows', [])
            ->assertJsonCount(2, 'refresh.remove_rows');

        $this->assertCount(2, $response->json('extensions.succeeded_incident_ids'));
        $this->assertSame('TX-BATCH-ROWS', $first['order']->fresh()->transaction_id);
        $this->assertSame('TX-BATCH-ROWS', $second['order']->fresh()->transaction_id);
    }

    public function test_service_case_page_is_unaffected_by_batch_workspace_routes(): void
    {
        $admin = $this->createAdmin();
        $pending = $this->createPendingCase($admin, 'SHOW');

        $this->actingAs($admin)
            ->get(route('incidents.show', $pending['incident']))
            ->assertOk()
            ->assertDontSee('data-workspace-action-form="batch-transaction"', false)
            ->assertDontSee('data-batch-assign', false);
    }
}
