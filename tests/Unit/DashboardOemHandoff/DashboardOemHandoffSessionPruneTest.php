<?php

namespace Tests\Unit\DashboardOemHandoff;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\IncidentSource;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardOemHandoff\DashboardOemHandoffSessionPruneService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOemHandoffSessionPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'dashboard_oem_handoff.prune_grace_hours_after_expiry' => 24,
        ]);
    }

    private function incidentFor(User $user): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-PRUNE-'.uniqid(),
            'serial_number' => 'SYNPRUNE1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Prune test',
            'description' => 'Prune test.',
            'status' => 'open',
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);
    }

    public function test_does_not_delete_unexpired_draft_ready_session(): void
    {
        $user = User::factory()->create();
        $incident = $this->incidentFor($user);

        $session = DashboardOemHandoffSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'oem' => 'mantra',
            'device_model' => 'MFS 110',
            'serial_number' => 'SYN1',
            'handoff_token_hash' => hash('sha256', 'token'),
            'status' => DashboardOemHandoffSessionStatus::DraftReady,
            'expires_at' => now()->addHour(),
            'draft_submitted_at' => now(),
            'draft_payload' => ['outcome' => 'found'],
        ]);

        app(DashboardOemHandoffSessionPruneService::class)->prune(dryRun: false);

        $this->assertDatabaseHas('dashboard_oem_handoff_sessions', ['id' => $session->id]);
    }

    public function test_deletes_reviewed_session_after_grace(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        $user = User::factory()->create();
        $incident = $this->incidentFor($user);

        $session = DashboardOemHandoffSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'oem' => 'mantra',
            'device_model' => 'MFS 110',
            'serial_number' => 'SYN1',
            'handoff_token_hash' => hash('sha256', 'token'),
            'status' => DashboardOemHandoffSessionStatus::Reviewed,
            'expires_at' => now()->addDays(2),
            'draft_reviewed_at' => now()->subHours(48),
            'draft_payload' => ['outcome' => 'found'],
        ]);

        Carbon::setTestNow('2026-11-03 12:00:00');

        $deleted = app(DashboardOemHandoffSessionPruneService::class)->prune(dryRun: false);

        $this->assertContains($session->id, $deleted);
        $this->assertDatabaseMissing('dashboard_oem_handoff_sessions', ['id' => $session->id]);

        Carbon::setTestNow();
    }

    public function test_prune_is_idempotent_when_nothing_eligible(): void
    {
        $service = app(DashboardOemHandoffSessionPruneService::class);
        $first = $service->prune(dryRun: false);
        $second = $service->prune(dryRun: false);

        $this->assertSame([], $first);
        $this->assertSame([], $second);
    }
}
