<?php

namespace Tests\Feature\PerformanceIntelligence;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\PerformanceIntelligenceSnapshot;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PerformanceIntelligenceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['performance_intelligence.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_capture_persists_explainable_snapshot(): void
    {
        $agent = $this->createAgent();
        $incident = $this->createIncident($agent);

        // created_at is not mass-assignable on AuditLog — set explicitly for day window.
        $audit = new AuditLog([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => ['status' => IncidentStatus::Open->value],
            'new_values' => ['status' => IncidentStatus::Resolved->value],
        ]);
        $audit->created_at = Carbon::parse('2026-08-04 14:00:00', 'Asia/Kolkata');
        $audit->save();

        $result = app(PerformanceIntelligenceEngine::class)->captureDay(
            Carbon::parse('2026-08-04', 'Asia/Kolkata'),
            [$agent->id],
        );

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['processed']);

        $snapshot = PerformanceIntelligenceSnapshot::query()
            ->where('user_id', $agent->id)
            ->whereDate('snapshot_date', '2026-08-04')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertGreaterThan(0, $snapshot->outcome_score);
        $this->assertGreaterThan(0, $snapshot->composite_score);
        $this->assertSame(1, (int) ($snapshot->inputs['resolved_count'] ?? 0));
        $this->assertNotEmpty($snapshot->explanations['outcome'] ?? []);
        $this->assertNotEmpty($snapshot->explanations['composite'] ?? []);
        $this->assertArrayHasKey('PERFORMANCE_INTELLIGENCE_ENABLED', $snapshot->feature_flags ?? []);
    }

    public function test_capture_is_idempotent_for_same_day(): void
    {
        $agent = $this->createAgent();

        $engine = app(PerformanceIntelligenceEngine::class);
        $date = Carbon::parse('2026-08-04', 'Asia/Kolkata');

        $engine->captureDay($date, [$agent->id]);
        $engine->captureDay($date, [$agent->id]);

        $this->assertSame(1, PerformanceIntelligenceSnapshot::query()->count());
    }

    public function test_explain_page_shows_breakdown_for_superadmin(): void
    {
        $agent = $this->createAgent();
        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        app(PerformanceIntelligenceEngine::class)->captureDay(
            Carbon::parse('2026-08-04', 'Asia/Kolkata'),
            [$agent->id],
        );

        $this->actingAs($super)
            ->get(route('admin.performance-intelligence.show', [
                'userId' => $agent->id,
                'date' => '2026-08-04',
            ]))
            ->assertOk()
            ->assertSee('Why this score')
            ->assertSee('Raw inputs')
            ->assertSee('Owner intuition vs score');
    }

    private function createAgent(): User
    {
        $user = User::factory()->create([
            'name' => 'PI Agent',
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user;
    }

    private function createIncident(User $agent): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD'.random_int(1000000, 9999999),
            'serial_number' => 'SN-PI',
            'customer_name' => 'PI Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'assigned_to_user_id' => $agent->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'PI case',
            'description' => 'PI case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
        ]);
    }
}
