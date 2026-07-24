<?php

namespace Tests\Unit\Dashboard;

use App\Data\RecentActivityThread;
use App\Enums\IncidentSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Support\Dashboard\RecentActivityPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecentActivityPresenterTest extends TestCase
{
    use RefreshDatabase;

    private RecentActivityPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->presenter = app(RecentActivityPresenter::class);
    }

    public function test_maps_payment_event_to_ira_stream_with_compact_time(): void
    {
        Carbon::setTestNow('2026-07-19 22:35:00');

        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        [$incident] = $this->createIncident($superadmin);

        $log = AuditLog::query()->create([
            'user_id' => $superadmin->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
        ]);
        $log->created_at = now()->subMinutes(11);
        $log->save();
        $log->setRelation('auditable', $incident);
        $log->setRelation('user', $superadmin);

        $streams = $this->presenter->presentStreams(collect([$log]), $superadmin);
        $item = $streams->ira->first()?->latest();

        $this->assertNotNull($item);
        $this->assertSame('ira', $item->stream);
        $this->assertSame('Payment Received', $item->title);
        $this->assertSame('Payment', $item->typePill);
        $this->assertSame($incident->display_reference, $item->incidentReference);
        $this->assertSame($incident->id, $item->entityIncidentId);
        $this->assertSame('11m', $item->compactTime);
        $this->assertCount(0, $streams->customer);
    }

    public function test_resolves_customer_name_and_order_reference_for_incident_label(): void
    {
        $user = User::factory()->create();
        [$incident, $order] = $this->createIncident($user, [
            'order_id' => 'RD345112',
            'customer_name' => 'Rahul Sharma',
        ]);

        $log = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
            'created_at' => now(),
        ]);
        $log->setRelation('auditable', $incident);
        $log->setRelation('user', $user);

        $item = $this->presenter->presentStreams(collect([$log]), $user)->team->first()?->latest();

        $this->assertNotNull($item);
        $this->assertSame('Rahul Sharma', $item->customerName);
        $this->assertSame('RD345112', $item->orderReference);
        $this->assertSame($incident->display_reference.' · RD345112', $item->incidentLabel());
        $this->assertSame($incident->id, $item->entityIncidentId);
        $this->assertSame($order->id, $incident->order_id);
    }

    public function test_entity_incident_id_is_set_when_incident_reference_is_shown_without_loaded_model(): void
    {
        $user = User::factory()->create();
        [$incident] = $this->createIncident($user);

        $log = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
            'created_at' => now(),
        ]);
        $log->setRelation('auditable', null);
        $log->setRelation('user', $user);

        $item = $this->presenter->presentStreams(collect([$log]), $user)->team->first()?->latest();

        $this->assertNotNull($item);
        $this->assertSame('SC'.$incident->id, $item->incidentReference);
        $this->assertSame($incident->id, $item->entityIncidentId);
        $this->assertNotSame('', $item->incidentLabel());
    }

    public function test_collapses_communication_events_into_team_stream(): void
    {
        Carbon::setTestNow('2026-07-19 22:35:00');

        $user = User::factory()->create();
        [$incident] = $this->createIncident($user);

        $lifecycle = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'communication_action.lifecycle',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'status' => 'sent',
                'execution_mode' => 'manual',
                'channels' => ['whatsapp'],
                'action_label' => 'Driver Installation Guide',
            ],
        ]);
        $lifecycle->created_at = now()->subSeconds(4);
        $lifecycle->save();

        $notification = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'notification.dispatched',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'aggregate_success' => true,
                'channel_results' => [
                    ['channel' => 'whatsapp', 'success' => true, 'status' => 'sent'],
                ],
            ],
        ]);
        $notification->created_at = now()->subSeconds(2);
        $notification->save();
        $lifecycle->setRelation('auditable', $incident);
        $lifecycle->setRelation('user', $user);
        $notification->setRelation('auditable', $incident);
        $notification->setRelation('user', $user);

        $item = $this->presenter->presentStreams(collect([$notification, $lifecycle]), $user)->team->first()?->latest();

        $this->assertNotNull($item);
        $this->assertSame('team', $item->stream);
        $this->assertSame('Communication Sent', $item->title);
        $this->assertSame('WhatsApp', $item->typePill);
        $this->assertSame(['WhatsApp'], $item->chips());
        $this->assertSame('', $item->channelBadge());
        $this->assertSame('WA→'.$incident->display_reference, $item->actionLabel());
        $this->assertSame('message', $item->iconKey());
        $this->assertSame('💬', $item->icon());
        $this->assertNull($item->statusMark());
    }

    public function test_maps_availability_change_to_operational_title(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $log = AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'user.availability_changed',
            'auditable_type' => $agent->getMorphClass(),
            'auditable_id' => $agent->id,
            'new_values' => [
                'status' => 'available',
                'source' => 'login',
            ],
            'created_at' => now(),
        ]);
        $log->setRelation('auditable', $agent);
        $log->setRelation('user', $agent);

        $item = $this->presenter->presentStreams(collect([$log]), $agent)->team->first()?->latest();

        $this->assertNotNull($item);
        $this->assertSame('Logged In', $item->title);
        $this->assertSame('Logged In', $item->actionLabel());
    }

    public function test_threads_consecutive_incident_activities(): void
    {
        $user = User::factory()->create();
        [$incident] = $this->createIncident($user);

        $logs = collect(['service_case.assigned', 'service_case.status_changed'])->map(function (string $event, int $index) use ($user, $incident) {
            $log = AuditLog::query()->create([
                'user_id' => $user->id,
                'event' => $event,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => $event === 'service_case.status_changed' ? ['status_label' => 'Open'] : [],
            ]);
            $log->created_at = now()->subMinutes($index);
            $log->save();
            $log->setRelation('auditable', $incident);
            $log->setRelation('user', $user);

            return $log;
        });

        $thread = $this->presenter->presentStreams($logs, $user)->team->first();

        $this->assertInstanceOf(RecentActivityThread::class, $thread);
        $this->assertTrue($thread->isCollapsible());
        $this->assertSame(2, $thread->count());
        $this->assertSame('Assigned', $thread->latest()?->title);
        $this->assertSame('Status Updated', $thread->items[1]->title);

        $html = view('components.dashboard.recent-activity-thread', [
            'thread' => $thread,
        ])->render();

        $this->assertStringContainsString('data-activity-thread-toggle', $html);
        $this->assertStringContainsString('data-activity-thread-history-source', $html);
        $this->assertStringContainsString('data-activity-thread-history', $html);
        $this->assertStringContainsString('>Assigned</span>', $html);
        $this->assertStringContainsString('>Status Upd</span>', $html);
        $this->assertStringNotContainsString('>History</span>', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-activity-thread-history[^>]*>\s*<div class="dashboard-activity-entry"/',
            $html,
        );
    }

    public function test_moves_automation_events_to_ira_stream_for_superadmin_only(): void
    {
        $automationEmail = (string) config('cashfree.system_user_email', 'system@example.com');
        $automationUser = User::factory()->create(['email' => $automationEmail]);
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        [$incident] = $this->createIncident($automationUser);

        $log = AuditLog::query()->create([
            'user_id' => $automationUser->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
            'created_at' => now(),
        ]);
        $log->setRelation('auditable', $incident);
        $log->setRelation('user', $automationUser);

        $agentStreams = $this->presenter->presentStreams(collect([$log]), $agent);
        $superadminStreams = $this->presenter->presentStreams(collect([$log]), $superadmin);

        $this->assertFalse($agentStreams->showIra);
        $this->assertCount(0, $agentStreams->ira);
        $this->assertTrue($superadminStreams->showIra);
        $this->assertCount(1, $superadminStreams->ira);
        $this->assertSame('IRA', $superadminStreams->ira->first()?->latest()?->typePill);
    }

    public function test_hides_internal_communication_lifecycle_steps(): void
    {
        $user = User::factory()->create();
        [$incident] = $this->createIncident($user);

        $opened = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'communication_action.lifecycle',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => ['status' => 'opened'],
            'created_at' => now(),
        ]);
        $opened->setRelation('auditable', $incident);
        $opened->setRelation('user', $user);

        $this->assertTrue($this->presenter->presentStreams(collect([$opened]), $user)->isEmpty());
    }

    public function test_activity_row_renders_customer360_data_attributes(): void
    {
        $user = User::factory()->create();
        [$incident] = $this->createIncident($user, [
            'order_id' => 'RD345112',
            'customer_name' => 'Amit Patel',
        ]);

        $log = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'refund.completed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
            'created_at' => now(),
        ]);
        $log->setRelation('auditable', $incident);
        $log->setRelation('user', $user);

        $item = $this->presenter->presentStreams(collect([$log]), $user)->team->first()?->latest();

        $this->assertNotNull($item);

        $html = view('components.dashboard.recent-activity-row', [
            'item' => $item,
            'showIncident' => true,
        ])->render();

        $this->assertStringContainsString('data-dashboard-activity-entry', $html);
        $this->assertStringContainsString('data-incident-id="'.$incident->id.'"', $html);
        $this->assertStringContainsString('data-customer-360-label="Amit Patel"', $html);
        $this->assertStringContainsString('RD345112', $html);
        $this->assertStringContainsString('Refunded', $html);
        $this->assertStringContainsString('dashboard-activity-entry-name', $html);
        $this->assertStringContainsString('title="Refund Completed"', $html);
        $this->assertStringNotContainsString('data-status=', $html);
        $this->assertStringNotContainsString('dashboard-activity-entry-chips', $html);
        $this->assertStringNotContainsString('>Team</span>', $html);
    }

    /**
     * @param  array{order_id?: string, customer_name?: string}  $orderOverrides
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(User $user, array $orderOverrides = [], ?Order $order = null): array
    {
        $order ??= Order::query()->create([
            'order_id' => $orderOverrides['order_id'] ?? 'RD1000001',
            'serial_number' => 'SN-0001',
            'customer_name' => $orderOverrides['customer_name'] ?? 'Test Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Recent activity test case',
            'description' => 'Recent activity test case.',
            'status' => 'open',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        $incident->setRelation('order', $order);

        return [$incident, $order];
    }
}
