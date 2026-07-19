<?php

namespace Tests\Unit\Dashboard;

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

    public function test_maps_automation_payment_event_to_customer_stream(): void
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
        $log->created_at = now()->subMinutes(2);
        $log->save();
        $log->setRelation('auditable', $incident);
        $log->setRelation('user', $user);

        $streams = $this->presenter->presentStreams(collect([$log]), $user);
        $item = $streams->customer->first();

        $this->assertNotNull($item);
        $this->assertSame('customer', $item->stream);
        $this->assertSame('Payment Received', $item->title);
        $this->assertSame('💰', $item->icon);
        $this->assertSame('Automation', $item->sourceBadge);
        $this->assertSame('success', $item->indicatorVariant);
        $this->assertStringContainsString('Incident', (string) $item->entityLabel);
        $this->assertSame($incident->id, $item->entityIncidentId);
        $this->assertSame('2 min ago', $item->relativeTime);
    }

    public function test_collapses_communication_lifecycle_events_within_window(): void
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
                'execution_mode' => 'automatic',
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

        $streams = $this->presenter->presentStreams(collect([$notification, $lifecycle]), $user);

        $this->assertCount(1, $streams->agentAdmin);
        $this->assertSame('Communication Sent', $streams->agentAdmin->first()->title);
        $this->assertSame('💬', $streams->agentAdmin->first()->icon);
        $this->assertContains('WhatsApp', $streams->agentAdmin->first()->includes);
        $this->assertContains('Notification', $streams->agentAdmin->first()->includes);
    }

    public function test_maps_order_entity_to_customer360_incident(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD10015664',
            'serial_number' => 'SN-15664',
            'customer_name' => 'Test Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $incident = $this->createIncident($user, $order);

        $log = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'order.updated',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => ['order_id' => $order->order_id],
            'created_at' => now(),
        ]);
        $order->setRelation('incidents', collect([$incident]));
        $log->setRelation('auditable', $order);
        $log->setRelation('user', $user);

        $item = $this->presenter->presentStreams(collect([$log]), $user)->agentAdmin->first();

        $this->assertNotNull($item);
        $this->assertSame('Order RD10015664', $item->entityLabel);
        $this->assertSame($incident->id, $item->entityIncidentId);
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

        $streams = $this->presenter->presentStreams(collect([$opened]), $user);

        $this->assertTrue($streams->isEmpty());
    }

    public function test_shows_system_stream_only_for_superadmin(): void
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

        $this->assertFalse($agentStreams->showSystem);
        $this->assertCount(0, $agentStreams->system);
        $this->assertTrue($superadminStreams->showSystem);
        $this->assertCount(1, $superadminStreams->system);
        $this->assertTrue($superadminStreams->system->first()->isAutomation);
        $this->assertSame('IRA', $superadminStreams->system->first()->actorName);
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
