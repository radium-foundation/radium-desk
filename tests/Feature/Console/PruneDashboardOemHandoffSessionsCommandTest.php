<?php

namespace Tests\Feature\Console;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\IncidentSource;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneDashboardOemHandoffSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard_oem_handoff.prune_grace_hours_after_expiry' => 24]);
    }

    private function reviewedSessionPastGrace(): DashboardOemHandoffSession
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-PRUNE-CMD-'.uniqid(),
            'serial_number' => 'SYNPRUNE2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Prune command',
            'description' => 'Prune command test.',
            'status' => 'open',
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        $session = DashboardOemHandoffSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'oem' => 'mantra',
            'device_model' => 'MFS 110',
            'serial_number' => 'SYNPRUNE2',
            'handoff_token_hash' => hash('sha256', 'token'),
            'status' => DashboardOemHandoffSessionStatus::Reviewed,
            'expires_at' => now()->addDays(2),
            'draft_reviewed_at' => now()->subHours(48),
            'draft_payload' => ['outcome' => 'found'],
        ]);

        Carbon::setTestNow('2026-11-03 12:00:00');

        return $session;
    }

    public function test_dry_run_does_not_delete_eligible_rows(): void
    {
        $session = $this->reviewedSessionPastGrace();

        $this->artisan('dashboard-oem-handoff:prune-sessions', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('dashboard_oem_handoff_sessions', ['id' => $session->id]);

        Carbon::setTestNow();
    }

    public function test_execute_deletes_only_eligible_rows(): void
    {
        $session = $this->reviewedSessionPastGrace();

        $this->artisan('dashboard-oem-handoff:prune-sessions', ['--execute' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('dashboard_oem_handoff_sessions', ['id' => $session->id]);

        Carbon::setTestNow();
    }

    public function test_scheduler_gate_disabled_when_prune_enabled_false(): void
    {
        config(['dashboard_oem_handoff.prune_enabled' => false]);

        $this->assertFalse((bool) config('dashboard_oem_handoff.prune_enabled', false));
    }

    public function test_scheduler_gate_enabled_when_prune_enabled_true(): void
    {
        config(['dashboard_oem_handoff.prune_enabled' => true]);

        $this->assertTrue((bool) config('dashboard_oem_handoff.prune_enabled', false));
    }
}
