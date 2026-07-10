<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Customer360RadiumBoxSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
        ]);
    }

    public function test_customer360_open_triggers_background_sync_for_orders_missing_enrichment(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::NotSynced,
            $incident->order->fresh()->radiumbox_sync_status,
        );

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            $incident->order->fresh()->radiumbox_sync_status,
        );
        $response->assertSee('Pending', false);
        $response->assertSee('data-customer-360-sync-diagnostics', false);
        $response->assertDontSee('data-customer-360-radiumbox-sync', false);
    }

    public function test_customer_360_shows_pending_serial_state_with_diagnostics(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($incident->order_id);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('Waiting for synchronization', false);
        $response->assertSee('Sync Status', false);
        $response->assertSee('Pending', false);
        $response->assertDontSee('data-customer-360-radiumbox-sync', false);
        $response->assertDontSee('heroicon-arrow-path', false);
    }

    public function test_customer_360_shows_failed_serial_state_with_retry_button_and_diagnostics(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markFailed(
            $incident->order_id,
            'Connection timed out after 5000 milliseconds',
            ['error_type' => 'connection_error'],
        );

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('Not Available', false);
        $response->assertSee('Failed', false);
        $response->assertSee('Reason', false);
        $response->assertSee('RadiumBox did not respond.', false);
        $response->assertSee('data-customer-360-radiumbox-sync', false);
        $response->assertSee('heroicon-arrow-path', false);
        $response->assertSee(route('dashboard.service-cases.customer-360.radiumbox-sync', $incident), false);
    }

    public function test_customer_360_hides_diagnostics_when_serial_exists(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $incident->order->update([
            'serial_number' => 'SN-AVAILABLE',
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('SN-AVAILABLE', false);
        $response->assertDontSee('data-customer-360-sync-diagnostics', false);
        $response->assertDontSee('data-customer-360-radiumbox-sync', false);
    }

    public function test_manual_radiumbox_sync_enriches_order_and_returns_refreshed_drawer(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '9389755',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        [$agent, $incident] = $this->createIncidentWithoutSerial();

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markFailed($incident->order_id, 'Previous failure');

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.customer-360.radiumbox-sync', $incident),
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', '✓ Device information synchronized successfully.');
        $response->assertJsonStructure(['device_html']);

        $incident->order->refresh();

        $this->assertSame('9389755', $incident->order->serial_number);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $incident->order->radiumbox_sync_status);
        $this->assertNull($incident->order->radiumbox_last_sync_error);
        $this->assertGreaterThan(0, $incident->order->radiumbox_sync_attempts);

        $html = (string) $response->json('device_html');
        $this->assertStringContainsString('9389755', $html);
        $this->assertStringContainsString('Synced from RadiumBox', $html);
        $this->assertStringNotContainsString('data-customer-360-sync-diagnostics', $html);
    }

    public function test_customer_360_shows_last_synced_freshness_when_serial_exists(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $incident->order->update([
            'serial_number' => 'SN-AVAILABLE',
            'radiumbox_last_sync_at' => now(),
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('SN-AVAILABLE', false);
        $response->assertSee('Synced from RadiumBox', false);
        $response->assertSee('customer-360-sync-freshness', false);
        $response->assertDontSee('data-customer-360-sync-diagnostics', false);
    }

    public function test_customer_360_shows_synchronization_history(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('Synchronization History', false);
        $response->assertSee('Order Created', false);
    }

    public function test_customer_360_device_endpoint_returns_partial_device_section(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($incident->order_id);

        $response = $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.customer-360.device', $incident),
        );

        $response->assertOk();
        $response->assertJsonPath('should_poll_sync', true);
        $response->assertJsonStructure(['html']);

        $html = (string) $response->json('html');
        $this->assertStringContainsString('data-customer-360-device-section', $html);
        $this->assertStringContainsString('Waiting for synchronization', $html);
        $this->assertStringContainsString('data-should-poll-sync="true"', $html);
    }

    public function test_customer_360_pending_state_sets_device_polling_attributes(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($incident->order_id);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertSee('data-should-poll-sync="true"', false);
        $response->assertSee(route('dashboard.service-cases.customer-360.device', $incident), false);
    }

    public function test_manual_radiumbox_sync_requires_authentication(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $this->post(route('dashboard.service-cases.customer-360.radiumbox-sync', $incident))
            ->assertRedirect(route('login'));
    }

    public function test_manual_radiumbox_sync_returns_friendly_error_when_radiumbox_lookup_fails(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response(null, 500),
        ]);

        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.customer-360.radiumbox-sync', $incident),
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Synchronization failed.');

        $incident->order->refresh();

        $this->assertNull($incident->order->serial_number);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Failed, $incident->order->radiumbox_sync_status);
        $this->assertNotNull($incident->order->radiumbox_last_sync_error);
    }

    public function test_manual_radiumbox_sync_maps_order_not_found_to_friendly_message(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ]),
        ]);

        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $response = $this->actingAs($agent)->postJson(
            route('dashboard.service-cases.customer-360.radiumbox-sync', $incident),
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'message',
            'Synchronization completed. Serial number is not yet available from RadiumBox.',
        );
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithoutSerial(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3438749',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'customer_name' => 'Test Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Missing serial',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
