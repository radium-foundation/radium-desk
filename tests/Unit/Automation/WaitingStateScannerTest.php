<?php

namespace Tests\Unit\Automation;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\WaitingStateScanner;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WaitingStateScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_scan_active_processes_waiting_states_in_chunks(): void
    {
        $this->createActiveWaitingState('RD-SCAN-1');
        $this->createActiveWaitingState('RD-SCAN-2');
        $this->createActiveWaitingState('RD-SCAN-3');

        $scannedIds = [];

        app(WaitingStateScanner::class)->scanActive(function (IncidentWaitingState $waitingState) use (&$scannedIds): void {
            $scannedIds[] = $waitingState->id;
        }, chunkSize: 2);

        $this->assertCount(3, $scannedIds);
        $this->assertSame(
            IncidentWaitingState::query()->active()->orderBy('id')->pluck('id')->all(),
            $scannedIds,
        );
    }

    public function test_scan_active_ignores_cleared_waiting_states_and_missing_policy_keys(): void
    {
        $included = $this->createActiveWaitingState('RD-SCAN-IN');
        $this->createActiveWaitingState('RD-SCAN-NO-POLICY', policyKey: null);
        $this->createClearedWaitingState('RD-SCAN-CLEARED');

        $scannedIds = [];

        app(WaitingStateScanner::class)->scanActive(function (IncidentWaitingState $waitingState) use (&$scannedIds): void {
            $scannedIds[] = $waitingState->id;
        });

        $this->assertSame([$included->id], $scannedIds);
    }

    private function createActiveWaitingState(string $orderId, ?string $policyKey = 'serial_number_default'): IncidentWaitingState
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scanner case',
            'description' => 'Scanner case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-01 09:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => $policyKey,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);
    }

    private function createClearedWaitingState(string $orderId): IncidentWaitingState
    {
        $waitingState = $this->createActiveWaitingState($orderId);
        $waitingState->update(['cleared_at' => Carbon::parse('2026-07-02 09:00:00')]);

        return $waitingState->fresh();
    }
}
