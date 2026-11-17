<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\WorkspaceContext;
use App\Jobs\ProcessAssignReferenceBatchFollowUpJob;
use App\Jobs\SendServiceReferenceDriverGuideBatchJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use App\Services\AssignReference\AssignReferenceBatchFollowUpOutboxWriter;
use App\Services\AssignReference\AssignReferenceBatchFollowUpProcessor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssignReferenceBatchFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array{order: Order, incident: Incident}
     */
    private function createPendingCase(User $admin, string $suffix): array
    {
        $order = Order::query()->create([
            'order_id' => "RD-P17-25-{$suffix}",
            'serial_number' => "SN-P17-25-{$suffix}",
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => "SC-P17-25-{$suffix}",
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "P17-25 {$suffix}",
            'description' => "P17-25 {$suffix}",
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        return compact('order', 'incident');
    }

    private function verifyOrder(User $admin, Order $order): void
    {
        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), ['confirmed' => true])
            ->assertOk();
    }

    public function test_five_order_batch_registers_outbox_before_response_and_commits_assignments(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incidentIds = [];
        $orders = [];

        for ($i = 1; $i <= 5; $i++) {
            $case = $this->createPendingCase($admin, (string) $i);
            $this->verifyOrder($admin, $case['order']);
            $incidentIds[] = $case['incident']->id;
            $orders[] = $case['order'];
        }

        $startedAt = microtime(true);

        $response = $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => $incidentIds,
                'transaction_id' => 'TX-P17-25-FIVE',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.operational_follow_up.assignment', 'committed')
            ->assertJsonPath('extensions.operational_follow_up.customer_whatsapp', 'pending');

        $httpMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->assertLessThan(3_000, $httpMs, "Five-order batch HTTP took {$httpMs}ms");

        foreach ($orders as $order) {
            $this->assertSame('TX-P17-25-FIVE', $order->fresh()->transaction_id);
        }

        $outbox = OutboxEvent::query()
            ->where('event_type', AssignReferenceBatchFollowUpOutboxWriter::EVENT_TYPE)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);

        Queue::assertPushed(ProcessAssignReferenceBatchFollowUpJob::class, 1);

        $job = collect(Queue::pushed(ProcessAssignReferenceBatchFollowUpJob::class))->first();
        $this->assertNotNull($job);
        $job->handle(app(AssignReferenceBatchFollowUpProcessor::class));

        $this->assertSame(OutboxEventStatus::Completed, $outbox->fresh()->status);
        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, 1);

        $this->assertSame(
            5,
            DB::table('notifications')
                ->where('type', TransactionCompletedNotification::class)
                ->count(),
        );

        $this->assertSame(
            5,
            count($response->json('extensions.succeeded_incident_ids')),
        );
    }

    public function test_outbox_processor_recovers_pending_follow_up_without_duplicate_driver_guides(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $first = $this->createPendingCase($admin, 'A');
        $second = $this->createPendingCase($admin, 'B');
        $this->verifyOrder($admin, $first['order']);
        $this->verifyOrder($admin, $second['order']);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$first['incident']->id, $second['incident']->id],
                'transaction_id' => 'TX-RECOVER',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertOk();

        $event = OutboxEvent::query()
            ->where('event_type', AssignReferenceBatchFollowUpOutboxWriter::EVENT_TYPE)
            ->first();

        $this->assertNotNull($event);

        app(AssignReferenceBatchFollowUpProcessor::class)->process($event);
        app(AssignReferenceBatchFollowUpProcessor::class)->process($event->fresh());

        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, 1);
    }

    public function test_database_rollback_does_not_create_follow_up_outbox_for_failed_assign(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $locked = $this->createPendingCase($admin, 'LOCKED');
        $locked['order']->update([
            'transaction_id' => 'TX-EXISTING',
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workspace.batch-transaction'), [
                'incident_ids' => [$locked['incident']->id],
                'transaction_id' => 'TX-FAIL',
                'workspace_context' => WorkspaceContext::Dashboard->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(
            0,
            OutboxEvent::query()
                ->where('event_type', AssignReferenceBatchFollowUpOutboxWriter::EVENT_TYPE)
                ->count(),
        );
    }
}
