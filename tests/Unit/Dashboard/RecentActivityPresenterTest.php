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

    public function test_maps_payment_event_to_customer_stream_with_compact_time(): void
    {
        Carbon::setTestNow('2026-07-19 22:35:00');

        $user = User::factory()->create();
        $incident = $this->createIncident($user);

        $log = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [],
        ]);
        $log->created_at = now()->subMinutes(11);
        $log->save();
        $log->setRelation('auditable', $incident);
        $log->setRelation('user', $user);

        $item = $this->presenter->presentStreams(collect([$log]), $user)->customer->first()?->latest();

        $this->assertNotNull($item);
        $this->assertSame('customer', $item->stream);
        $this->assertSame('Payment Received', $item->title);
        $this->assertSame('Payment', $item->typePill);
        $this->assertSame($incident->display_reference, $item->incidentReference);
        $this->assertSame($incident->id, $item->entityIncidentId);
        $this->assertSame('11m', $item->compactTime);
    }

    public function test_collapses_communication_events_into_team_stream(): void
    {
        Carbon::setTestNow('2026-07-19 22:35:00');

        $user = User::factory()->create();
        $incident = $this->createIncident($user);

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
    }

    public function test_threads_consecutive_incident_activities(): void
    {
        $user = User::factory()->create();
        $incident = $this->createIncident($user);

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
    }

    public function test_moves_automation_events_to_ira_stream_for_superadmin_only(): void
    {
        $automationEmail = (string) config('cashfree.system_user_email', 'system@example.com');
        $automationUser = User::factory()->create(['email' => $automationEmail]);
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        $incident = $this->createIncident($automationUser);

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
        $incident = $this->createIncident($user);

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

    private function createIncident(User $user, ?Order $order = null): Incident
    {
        $order ??= Order::query()->create([
            'order_id' => 'RD1000001',
            'serial_number' => 'SN-0001',
            'customer_name' => 'Test Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
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
    }
}
