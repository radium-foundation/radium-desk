<?php

namespace Tests\Feature;

use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OrderActivityTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LiveOperationalExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_live_refresh_returns_partial_payload(): void
    {
        $admin = User::factory()->create(['name' => 'Avinash Jha']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-LIVE-001',
            'serial_number' => 'SN-LIVE-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-LIVE-001',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Live refresh case',
            'description' => 'Live refresh case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('dashboard.live', ['filter' => 'all']));

        $response->assertOk()
            ->assertJsonStructure([
                'kpi_strip_html',
                'service_case_filter_counts',
                'service_cases_empty',
                'service_cases_empty_html',
                'rows',
                'incident_ids',
                'total_count',
                'has_more',
                'loaded_count',
            ])
            ->assertJsonPath('service_cases_empty', false)
            ->assertJsonPath('service_case_filter_counts.all', 1);

        $this->assertNotEmpty($response->json('rows'));
        $this->assertStringContainsString('SC-LIVE-001', $response->json('rows.0.html'));
    }

    public function test_dashboard_live_refresh_respects_filter(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-LIVE-FILTER',
            'serial_number' => 'SN-LIVE-FILTER',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'transaction_id' => 'TXN-LIVE-FILTER',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-LIVE-COMPLETE',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Completed case',
            'description' => 'Completed case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('dashboard.live', ['filter' => 'completed']));

        $response->assertOk()
            ->assertJsonPath('service_cases_empty', true);

        $this->assertStringNotContainsString(
            'SC-LIVE-COMPLETE',
            collect($response->json('rows'))->pluck('html')->implode(''),
        );
    }

    public function test_notification_poll_returns_unread_count_and_bell_markup(): void
    {
        $admin = User::factory()->create(['name' => 'Shipra Kumari']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-POLL-001',
            'serial_number' => 'SN-POLL-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-POLL-001',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Poll case',
            'description' => 'Poll case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $admin->notify(new \App\Notifications\HighPriorityServiceCaseNotification($incident, $admin));

        $response = $this->actingAs($admin)->getJson(route('notifications.poll'));

        $response->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('badge', '1')
            ->assertJsonStructure(['bell_html', 'new_notifications']);

        $this->assertStringContainsString('notification-bell-btn', $response->json('bell_html'));
    }

    public function test_notification_poll_returns_new_notifications_since_timestamp(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-POLL-SINCE',
            'serial_number' => 'SN-POLL-SINCE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-POLL-SINCE',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Since poll case',
            'description' => 'Since poll case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $admin->notify(new \App\Notifications\HighPriorityServiceCaseNotification($incident, $admin));

        $since = now()->subMinute()->toIso8601String();

        $response = $this->actingAs($admin)->getJson(route('notifications.poll', ['since' => $since]));

        $response->assertOk()
            ->assertJsonCount(1, 'new_notifications')
            ->assertJsonPath('new_notifications.0.title', 'High Priority Service Case');
    }

    public function test_order_activity_timeline_renders_combined_events(): void
    {
        $admin = User::factory()->create(['name' => 'Avinash Jha']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $assignee = User::factory()->create(['name' => 'Shipra Kumari', 'first_name' => 'Shipra', 'last_name' => 'Kumari']);
        $assignee->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TIMELINE-001',
            'serial_number' => 'SN-TIMELINE-001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TIMELINE-001',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Timeline case',
            'description' => 'Timeline case.',
            'status' => 'open',
            'created_by' => $admin->id,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $auditLogService = app(AuditLogService::class);
        $request = Request::create('/test', 'POST');

        $auditLogService->log(
            userId: $admin->id,
            event: 'transaction.assigned',
            auditable: $order,
            newValues: ['transaction_id' => 'TX10231'],
            request: $request,
        );

        $auditLogService->log(
            userId: $admin->id,
            event: 'service_case.assigned',
            auditable: $incident,
            newValues: ['assigned_to_user_id' => $assignee->id],
            request: $request,
        );

        Remark::query()->create([
            'user_id' => $admin->id,
            'remarkable_type' => $order->getMorphClass(),
            'remarkable_id' => $order->id,
            'body' => 'Customer follow-up required.',
        ]);

        $remark = Remark::query()->first();
        $auditLogService->log(
            userId: $admin->id,
            event: 'created',
            auditable: $remark,
            newValues: ['body' => $remark->body],
            request: $request,
        );

        $timeline = app(OrderActivityTimelineService::class)->forOrder($order->fresh());

        $titles = $timeline->pluck('title')->all();

        $this->assertContains('Service Case SC-TIMELINE-001 created', $titles);
        $this->assertTrue(collect($titles)->contains(fn (string $title) => str_contains($title, 'Assigned to Shipra')));
        $this->assertContains('Transaction ID added', $titles);
        $this->assertContains('Remark added', $titles);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Activity Timeline')
            ->assertSee('TX10231')
            ->assertSee('Service Case SC-TIMELINE-001 created');
    }

    public function test_order_activity_timeline_does_not_duplicate_events(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TIMELINE-DEDUPE',
            'serial_number' => 'SN-TIMELINE-DEDUPE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TIMELINE-DEDUPE',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Dedupe case',
            'description' => 'Dedupe case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $timeline = app(OrderActivityTimelineService::class)->forOrder($order->fresh());
        $dedupeKeys = $timeline->pluck('dedupeKey');

        $this->assertSame($dedupeKeys->count(), $dedupeKeys->unique()->count());
    }

    public function test_order_activity_timeline_includes_refund_and_approval_events(): void
    {
        $admin = User::factory()->create(['name' => 'Jayram Singh']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-TIMELINE-APPROVAL',
            'serial_number' => 'SN-TIMELINE-APPROVAL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TIMELINE-APPROVAL',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Approval case',
            'description' => 'Approval case.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'RF-TIMELINE-001',
            'amount' => 100,
            'reason' => 'Duplicate charge',
            'status' => 'pending',
            'requested_by' => $admin->id,
        ]);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-TIMELINE-001',
            'description' => 'Replacement approval',
            'created_by' => $admin->id,
        ]);
        $approval->incidents()->attach($incident->id, ['linked_by' => $admin->id]);

        $auditLogService = app(AuditLogService::class);
        $request = Request::create('/test', 'POST');

        $auditLogService->log(
            userId: $admin->id,
            event: 'created',
            auditable: $refund,
            newValues: ['reference_no' => $refund->reference_no],
            request: $request,
        );

        $auditLogService->log(
            userId: $admin->id,
            event: 'incident_linked',
            auditable: $approval,
            newValues: [
                'approval_number' => $approval->approval_number,
                'incident_id' => $incident->id,
                'reference_no' => $incident->reference_no,
            ],
            request: $request,
        );

        $auditLogService->log(
            userId: $admin->id,
            event: 'deleted',
            auditable: $approval,
            oldValues: ['approval_number' => $approval->approval_number],
            request: $request,
        );

        $timeline = app(OrderActivityTimelineService::class)->forOrder($order->fresh());
        $titles = $timeline->pluck('title')->all();

        $this->assertContains('Refund request created', $titles);
        $this->assertContains('Approval linked', $titles);
        $this->assertContains('Approval closed', $titles);
    }
}
