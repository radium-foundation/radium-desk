<?php

namespace Tests\Unit\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationActionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_resolve_status_returns_available_when_no_lifecycle_events_exist(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(CommunicationActionLifecycleService::class);

        $this->assertSame(
            CommunicationActionLifecycleStatus::Available,
            $service->resolveStatus($incident, CommunicationActionKey::ReviewRequest->value, $agent),
        );
    }

    public function test_record_opened_writes_lifecycle_audit_event(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(CommunicationActionLifecycleService::class);
        $service->recordOpened($incident, $agent, CommunicationActionKey::ReviewRequest->value);

        $this->assertDatabaseHas('audit_logs', [
            'event' => CommunicationActionLifecycleAuditService::EVENT,
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->id,
            'user_id' => $agent->id,
        ]);

        $auditLog = AuditLog::query()->first();
        $this->assertSame('opened', $auditLog->new_values['status']);
        $this->assertSame('review_request', $auditLog->new_values['action_key']);
        $this->assertSame('manual', $auditLog->new_values['execution_mode']);
    }

    public function test_record_successful_execution_writes_sent_and_completed_events(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(CommunicationActionLifecycleService::class);
        $service->recordSuccessfulExecution(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::ReviewRequest->value,
            channels: ['whatsapp'],
        );

        $events = AuditLog::query()
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame('sent', $events[0]->new_values['status']);
        $this->assertSame(['whatsapp'], $events[0]->new_values['channels']);
        $this->assertSame('completed', $events[1]->new_values['status']);
    }

    public function test_resolve_status_returns_available_after_completed_cycle(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(CommunicationActionLifecycleService::class);
        $service->recordSuccessfulExecution(
            incident: $incident,
            actor: $agent,
            actionKey: CommunicationActionKey::ReviewRequest->value,
            channels: ['whatsapp'],
        );

        $this->assertSame(
            CommunicationActionLifecycleStatus::Available,
            $service->resolveStatus($incident, CommunicationActionKey::ReviewRequest->value, $agent),
        );
    }

    public function test_resolve_status_returns_opened_after_dialog_open(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(CommunicationActionLifecycleService::class);
        $service->recordOpened($incident, $agent, CommunicationActionKey::ReviewRequest->value);

        $this->assertSame(
            CommunicationActionLifecycleStatus::Opened,
            $service->resolveStatus($incident, CommunicationActionKey::ReviewRequest->value, $agent),
        );
    }

    public function test_lifecycle_status_transition_rules(): void
    {
        $this->assertTrue(
            CommunicationActionLifecycleStatus::Available->canTransitionTo(
                CommunicationActionLifecycleStatus::Opened,
            ),
        );

        $this->assertTrue(
            CommunicationActionLifecycleStatus::Opened->canTransitionTo(
                CommunicationActionLifecycleStatus::Sent,
            ),
        );

        $this->assertTrue(
            CommunicationActionLifecycleStatus::Opened->canTransitionTo(
                CommunicationActionLifecycleStatus::Skipped,
            ),
        );

        $this->assertFalse(
            CommunicationActionLifecycleStatus::Available->canTransitionTo(
                CommunicationActionLifecycleStatus::Sent,
            ),
        );
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(User $actor): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-LIFE',
            'serial_number' => 'SN-LIFE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Communication lifecycle case',
            'description' => 'Communication lifecycle case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }
}
