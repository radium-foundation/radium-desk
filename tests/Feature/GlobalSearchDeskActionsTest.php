<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchDeskActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @param  array<string, mixed>  $incidentAttributes
     */
    private function createServiceCase(User $user, array $orderAttributes = [], array $incidentAttributes = []): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderAttributes['order_id'] ?? 'RD-'.uniqid(),
            'serial_number' => $orderAttributes['serial_number'] ?? 'SN-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => $orderAttributes['customer_name'] ?? 'Desk Customer',
            'customer_phone' => $orderAttributes['customer_phone'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $incidentAttributes['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Desk action search case',
            'description' => 'Desk action search case.',
            'status' => $incidentAttributes['status'] ?? IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $incidentAttributes['assigned_to_user_id'] ?? $user->id,
        ]);
    }

    public function test_active_service_case_result_includes_open_action_metadata(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3434509',
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3434509']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('results.0.actions.incident_id', $incident->id)
            ->assertJsonPath('results.0.actions.display_reference', $incident->display_reference)
            ->assertJsonPath('results.0.actions.is_closed', false)
            ->assertJsonPath('results.0.actions.can_reopen', false)
            ->assertJsonPath('results.0.actions.reopen_url', null)
            ->assertJsonPath(
                'results.0.actions.customer_360_url',
                route('dashboard.service-cases.customer-360', $incident),
            );
    }

    public function test_closed_service_case_result_includes_reopen_metadata_when_allowed(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createServiceCase($agent, [
            'order_id' => 'RD3437143',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3437143']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('results.0.actions.is_closed', true)
            ->assertJsonPath('results.0.actions.can_reopen', true)
            ->assertJsonPath(
                'results.0.actions.reopen_url',
                route('incidents.workspace.action', $incident),
            )
            ->assertJsonPath(
                'results.0.actions.reopen_workspace_context',
                WorkspaceContext::ServiceCase->value,
            );
    }

    public function test_closed_service_case_omits_reopen_metadata_without_update_permission(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('incidents.view');

        $incident = $this->createServiceCase($owner, [
            'order_id' => 'RD3437999',
        ], [
            'status' => IncidentStatus::Closed,
        ]);

        $this->actingAs($viewer)
            ->getJson(route('search.index', ['q' => 'RD3437999']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('results.0.actions.is_closed', true)
            ->assertJsonPath('results.0.actions.can_reopen', false)
            ->assertJsonPath('results.0.actions.reopen_url', null)
            ->assertJsonPath(
                'results.0.actions.customer_360_url',
                route('dashboard.service-cases.customer-360', $incident),
            );
    }

    public function test_multi_result_search_includes_actions_for_each_service_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $openCase = $this->createServiceCase($agent, ['order_id' => 'RD-MULTI-OPEN']);
        $closedCase = $this->createServiceCase($agent, ['order_id' => 'RD-MULTI-CLOSED'], [
            'status' => IncidentStatus::Closed,
        ]);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD-MULTI-']))
            ->assertOk()
            ->assertJsonPath('match_count', 2);

        $results = collect($response->json('results'))->keyBy('order_id');

        $this->assertFalse($results['RD-MULTI-OPEN']['actions']['can_reopen']);
        $this->assertTrue($results['RD-MULTI-CLOSED']['actions']['can_reopen']);
        $this->assertSame($openCase->id, $results['RD-MULTI-OPEN']['actions']['incident_id']);
        $this->assertSame($closedCase->id, $results['RD-MULTI-CLOSED']['actions']['incident_id']);
    }
}
