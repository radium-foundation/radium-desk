<?php

namespace Tests\Feature\LegacyOrder;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LegacyOrderDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
        ]);
    }

    public function test_legacy_imported_closed_case_is_discoverable_via_quick_create_search(): void
    {
        Http::fake();
        Queue::fake();

        $agent = User::factory()->create(['name' => 'Discovery Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3395988',
            'serial_number' => 'SN3395988',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $agent->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC07017',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy imported closed case',
            'description' => 'Imported legacy order.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3395988',
            ])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', false)
            ->assertJsonPath('legacy_preview', null)
            ->assertJsonPath('matches.0.id', $order->id)
            ->assertJsonPath('matches.0.existing_case.incident_id', $incident->id)
            ->assertJsonPath('matches.0.existing_case.display_reference', 'SC07017')
            ->assertJsonPath('matches.0.existing_case.status', IncidentStatus::Closed->value)
            ->assertJsonPath('matches.0.existing_case.status_label', 'Closed')
            ->assertJsonPath('matches.0.existing_case.is_closed', true)
            ->assertJsonPath('matches.0.existing_case.can_reopen', true)
            ->assertJsonPath('matches.0.existing_case.customer_360_url', route('dashboard.service-cases.customer-360', $incident));

        Http::assertNothingSent();
    }

    public function test_quick_create_does_not_call_radiumbox_when_local_closed_order_exists(): void
    {
        Http::fake([
            'admin.radiumbox.com/*' => Http::response([], 500),
        ]);
        Queue::fake();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3395988',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC07017',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed legacy case',
            'description' => 'Closed.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3395988',
            ])
            ->assertOk()
            ->assertJsonPath('legacy_preview', null)
            ->assertJsonPath('matches.0.existing_case.is_closed', true);

        Http::assertNothingSent();
    }

    public function test_closed_case_can_open_customer_360_by_incident_and_order(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3395988',
            'serial_number' => 'SN3395988',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $agent->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC07017',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy imported closed case',
            'description' => 'Imported legacy order.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-customer-360-content', false)
            ->assertSee('RD3395988', false);

        $this->actingAs($agent)
            ->get(route('dashboard.orders.customer-360', $order))
            ->assertOk()
            ->assertSee('data-customer-360-content', false)
            ->assertSee('RD3395988', false);
    }

    public function test_global_search_finds_closed_legacy_imported_service_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3395988',
            'serial_number' => 'SN3395988',
            'legacy_source' => 'radiumbox',
            'legacy_imported_at' => now(),
            'legacy_imported_by_user_id' => $agent->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC07017',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed legacy imported case',
            'description' => 'Closed legacy imported case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3395988']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertJsonPath('results.0.status', IncidentStatus::Closed->label());
    }
}
