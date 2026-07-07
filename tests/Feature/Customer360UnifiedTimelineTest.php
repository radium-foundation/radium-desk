<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TimelineEventType;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Customer360\Customer360SlaMetricsService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Customer360UnifiedTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['radiumbox.enabled' => true]);
    }

    public function test_unified_timeline_includes_sync_notification_and_appointment_events(): void
    {
        [$agent, $incident, $order] = $this->createFixture();

        app(AuditLogService::class)->log(
            userId: null,
            event: ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
            auditable: $incident,
            newValues: ['source' => 'background'],
        );

        app(AuditLogService::class)->log(
            userId: $agent->id,
            event: RadiumBoxSyncAuditService::EVENT_MANUAL_SYNC,
            auditable: $order,
            newValues: [
                'success' => true,
                'sync_source' => 'manual',
            ],
        );

        app(AuditLogService::class)->log(
            userId: null,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'request_serial',
                'aggregate_success' => true,
                'channel_results' => [[
                    'channel' => 'email',
                    'status' => 'sent',
                    'success' => true,
                    'retryable' => false,
                    'message' => null,
                    'timestamp' => now()->toIso8601String(),
                    'duration_ms' => 120,
                ]],
            ],
        );

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => $order->customer_phone,
        ]);

        $events = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident));
        $types = $events->map(fn ($event) => $event->type);

        $this->assertTrue($types->contains(TimelineEventType::Synchronization));
        $this->assertTrue($types->contains(TimelineEventType::Email));
        $this->assertTrue($types->contains(TimelineEventType::Appointment));
    }

    public function test_customer_360_timeline_tab_renders_operations_center_sections(): void
    {
        [$agent, $incident] = $this->createFixture();

        $response = $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0',
        );

        $response->assertOk();
        $response->assertJsonStructure(['html', 'has_more', 'loaded_count']);
        $html = (string) $response->json('html');
        $this->assertStringContainsString('Customer Timeline', $html);
        $this->assertStringContainsString('Operations Health', $html);
        $this->assertStringContainsString('SLA Metrics', $html);
        $this->assertStringContainsString('data-customer-360-timeline-section', $html);
    }

    public function test_timeline_endpoint_supports_json_refresh_payload(): void
    {
        [$agent, $incident] = $this->createFixture();

        $response = $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.customer-360.timeline', $incident).'?offset=0',
        );

        $response->assertOk();
        $response->assertJsonStructure(['html', 'has_more', 'loaded_count']);
        $this->assertStringContainsString('data-customer-360-timeline-section', (string) $response->json('html'));
    }

    public function test_timeline_events_support_new_filter_tags(): void
    {
        [$agent, $incident, $order] = $this->createFixture();

        app(AuditLogService::class)->log(
            userId: null,
            event: ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
            auditable: $incident,
            newValues: [],
        );

        $events = $this->flattenTimeline(app(Customer360TimelineService::class)->forIncident($incident));
        $syncEvent = $events->first(fn ($event) => $event->title === 'Background sync started');

        $this->assertNotNull($syncEvent);
        $this->assertTrue($syncEvent->matchesFilter('synchronization'));
        $this->assertTrue($syncEvent->matchesFilter('system'));
        $this->assertFalse($syncEvent->matchesFilter('payments'));
    }

    public function test_sla_metrics_service_batches_audit_log_queries(): void
    {
        [, , $order] = $this->createFixture();

        for ($index = 0; $index < 3; $index++) {
            $relatedOrder = Order::query()->create([
                'order_id' => 'RD-SLA-BATCH-'.$index,
                'customer_phone' => $order->customer_phone,
                'status' => 'active',
                'created_by' => $order->created_by,
            ]);

            $relatedIncident = Incident::query()->create([
                'order_id' => $relatedOrder->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'SLA batch case '.$index,
                'description' => 'SLA batch case.',
                'status' => IncidentStatus::Open,
                'created_by' => $order->created_by,
                'updated_by' => $order->created_by,
            ]);

            app(AuditLogService::class)->log(
                userId: null,
                event: NotificationAuditTrailService::EVENT_DISPATCHED,
                auditable: $relatedIncident,
                newValues: [
                    'channel_results' => [[
                        'channel' => 'email',
                        'success' => true,
                    ]],
                ],
            );
        }

        $auditLogQueries = 0;

        AuditLog::resolveConnection()->listen(function ($query) use (&$auditLogQueries): void {
            if (str_contains(strtolower($query->sql), 'audit_logs')) {
                $auditLogQueries++;
            }
        });

        app(Customer360SlaMetricsService::class)->forOrder($order->fresh());

        $this->assertSame(1, $auditLogQueries);
    }

    public function test_sla_metrics_service_returns_stage_aggregates(): void
    {
        [, , $order] = $this->createFixture();

        $order->update([
            'payment_date' => now()->subHours(3),
            'created_at' => now()->subHours(2),
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'radiumbox_last_sync_at' => now()->subHour(),
        ]);

        $metrics = app(Customer360SlaMetricsService::class)->forOrder($order->fresh());

        $this->assertGreaterThan(0, $metrics->stages['payment_to_order']['sample_size']);
        $this->assertNotNull($metrics->stages['payment_to_order']['median_minutes']);
    }

    public function test_batch_recovery_requires_operations_permission(): void
    {
        [$agent, , $order] = $this->createFixture();

        $this->actingAs($agent)
            ->postJson(route('admin.operations.radiumbox.batch-recover'), [
                'order_ids' => [$order->id],
            ])
            ->assertForbidden();
    }

    public function test_batch_recovery_dispatches_jobs_for_eligible_orders(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [, , $order] = $this->createFixture();
        $order->update([
            'cashfree_payment_id' => 'cf_batch_1',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => now()->subHours(3),
            'radiumbox_sync_attempts' => 1,
            'created_at' => now()->subHours(4),
        ]);

        $this->travel(3)->hours();

        $response = $this->actingAs($admin)->postJson(route('admin.operations.radiumbox.batch-recover'), [
            'order_ids' => [$order->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.recovered', 1);
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_batch_recovery_skips_ineligible_orders(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [, , $order] = $this->createFixture();
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_sync_attempts' => 10,
        ]);

        $result = app(RadiumBoxSyncRecoveryService::class)->recoverOrders([$order->id]);

        Queue::assertNothingPushed();
        $this->assertSame(0, $result->recovered);
        $this->assertSame(1, $result->skipped);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createFixture(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-UNIFIED-'.uniqid(),
            'cashfree_payment_id' => 'cf_'.uniqid(),
            'serial_number' => null,
            'customer_name' => 'Timeline Customer',
            'customer_phone' => '9876501234',
            'status' => 'active',
            'created_by' => $agent->id,
            'payment_date' => now()->subHour(),
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Unified timeline',
            'description' => 'Unified timeline test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident, $order];
    }

    private function flattenTimeline($viewModel)
    {
        return $viewModel->groups->flatMap(fn ($group) => $group->events);
    }
}
