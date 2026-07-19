<?php

namespace Tests\Unit\Assignment;

use App\Enums\Assignment\AssignmentTrigger;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Support\Assignment\CommunicationOwnershipGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationOwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_preserves_ownership_when_incident_is_assigned(): void
    {
        $assignee = User::factory()->create();
        $incident = $this->createIncident($assignee);
        $guard = new CommunicationOwnershipGuard;

        $this->assertTrue($guard->preservesOwnership($incident));
        $this->assertTrue($guard->shouldSkipAssignment($incident, AssignmentTrigger::CommunicationIntake));
    }

    public function test_does_not_skip_non_communication_triggers(): void
    {
        $assignee = User::factory()->create();
        $incident = $this->createIncident($assignee);
        $guard = new CommunicationOwnershipGuard;

        $this->assertFalse($guard->shouldSkipAssignment($incident, AssignmentTrigger::ValidationSuccess));
    }

    private function createIncident(?User $assignee = null): Incident
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-GUARD-'.uniqid(),
            'serial_number' => 'SN-GUARD-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-GUARD-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Guard test case',
            'description' => '',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
