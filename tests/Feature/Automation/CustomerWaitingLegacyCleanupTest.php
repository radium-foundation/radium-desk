<?php

namespace Tests\Feature\Automation;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\CustomerWaitingLegacyCleanupService;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\ServiceCaseActivityTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerWaitingLegacyCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'waiting_states.lifecycle_deployment_at' => '2026-07-07 00:00:00',
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'mail.enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'first_name' => 'Ira',
            'last_name' => 'Automation',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_legacy_waiting_case_is_closed_with_resolution_reason_and_remark(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident, $waitingState] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-05 10:00:00'),
        );

        $summary = app(CustomerWaitingLegacyCleanupService::class)->cleanup();

        $this->assertSame(1, $summary->totalFound);
        $this->assertSame(1, $summary->casesClosed);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame([], $summary->skipReasons);

        $incident = $incident->fresh();
        $waitingState = $waitingState->fresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertNotNull($waitingState->cleared_at);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => CustomerWaitingLifecycleService::LEGACY_CLEANUP_REMARK,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);

        $auditLog = \App\Models\AuditLog::query()
            ->where('event', CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED)
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertSame(
            ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
            $auditLog?->new_values['resolution_reason'] ?? null,
        );
    }

    public function test_dry_run_does_not_close_cases(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-05 10:00:00'),
        );

        $this->artisan('customer-waiting:cleanup-legacy', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Total found: 1')
            ->expectsOutputToContain('Would close: 1')
            ->expectsOutputToContain('Cases closed: 0')
            ->expectsOutputToContain('Skipped: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED,
        ]);
    }

    public function test_cleanup_failure_increments_skipped_with_reason(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-05 10:00:00'),
        );

        $this->partialMock(\App\Services\AuditLogService::class, function ($mock): void {
            $mock->shouldReceive('log')
                ->andThrow(new \RuntimeException('Simulated cleanup failure'));
        });

        $summary = app(CustomerWaitingLegacyCleanupService::class)->cleanup();

        $this->assertSame(1, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(['close failed' => 1], $summary->skipReasons);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_post_deployment_waiting_case_is_not_closed(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-07 09:00:00'),
        );

        $summary = app(CustomerWaitingLegacyCleanupService::class)->cleanup();

        $this->assertSame(0, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_already_closed_case_is_not_included(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-05 10:00:00'),
        );

        $incident->update(['status' => IncidentStatus::Closed]);

        $summary = app(CustomerWaitingLegacyCleanupService::class)->cleanup();

        $this->assertSame(0, $summary->totalFound);
        $this->assertSame(0, $summary->casesClosed);
    }

    public function test_cleanup_writes_timeline_entry_without_customer_notifications(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-05 10:00:00'),
        );

        app(CustomerWaitingLegacyCleanupService::class)->cleanup();

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $titles = $timeline->pluck('title')->all();

        $this->assertContains('Closed during customer waiting lifecycle migration', $titles);

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'notification.dispatched',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_legacy_cleanup_preserves_ira_lifecycle_history(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        [, $incident] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-05 10:00:00'),
        );

        app(CustomerWaitingLegacyCleanupService::class)->cleanup();

        $history = app(CustomerWaitingLifecycleService::class)->lifecycleHistory($incident->fresh());

        $this->assertIsArray($history);
        $this->assertTrue($history['auto_closed']);
        $this->assertSame(
            ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
            $history['resolution_reason'],
        );
    }

    /**
     * @return array{0: User, 1: Incident, 2: \App\Models\IncidentWaitingState}
     */
    private function createWaitingScenario(Carbon $startedAt): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-LC-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Legacy Waiting Customer',
            'customer_phone' => '9876543210',
            'customer_email' => 'legacy.waiting@example.com',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy customer waiting cleanup',
            'description' => 'Waiting for customer input.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $waitingState = app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::Photos,
            actor: $agent,
            startedAt: $startedAt,
        );

        return [$agent, $incident, $waitingState];
    }
}
