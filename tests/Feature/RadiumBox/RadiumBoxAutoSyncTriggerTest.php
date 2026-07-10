<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RadiumBoxSyncTrigger;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIntakeSearchService;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxAutoSyncTriggerService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RadiumBoxAutoSyncTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.auto_sync.enabled' => true,
            'radiumbox.auto_sync.min_interval_minutes' => 30,
        ]);
    }

    public function test_customer360_open_queues_sync_when_order_needs_enrichment(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($incident): bool {
            return $job->orderId === $incident->order_id;
        });
    }

    public function test_fresh_sync_does_not_queue_again_when_order_is_already_enriched(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createEnrichedIncident();

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_recent_sync_attempt_respects_cooldown(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createIncidentWithoutSerial();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markSynced($incident->order_id);
        $syncStore->recordProcessingAttempt($incident->order_id);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_pending_sync_status_does_not_queue_duplicate_job(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createIncidentWithoutSerial();
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($incident->order_id);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_workspace_open_queues_sync_when_needed(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $this->actingAs($agent)
            ->get(route('incidents.show', $incident))
            ->assertOk();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_order_search_match_queues_sync_for_existing_desk_order(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SEARCH-SYNC',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Search Customer',
            'customer_phone' => '9876501234',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        app(CustomerIntakeSearchService::class)->search(
            orderId: 'RD-SEARCH-SYNC',
            user: $agent,
        );

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_auto_sync_trigger_service_reports_dispatch_eligibility(): void
    {
        Queue::fake();

        [$agent, $incident] = $this->createIncidentWithoutSerial();
        $service = app(RadiumBoxAutoSyncTriggerService::class);

        $this->assertTrue($service->shouldDispatch($incident->order));
        $this->assertTrue($service->maybeDispatch($incident->order, RadiumBoxSyncTrigger::Customer360Open));

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithoutSerial(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-SYNC-1',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Auto Sync Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Auto sync case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createEnrichedIncident(): array
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $incident->order->update([
            'serial_number' => 'M250546898',
            'device_model' => 'Access FM220 L1',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'radiumbox_last_sync_at' => now(),
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id);

        return [$agent, $incident->fresh(['order'])];
    }
}
