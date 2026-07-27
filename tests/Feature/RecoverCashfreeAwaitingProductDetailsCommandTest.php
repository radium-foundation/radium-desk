<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RecoverCashfreeAwaitingProductDetailsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
        ]);

        $this->createAutomationActor();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('cashfree:recover-awaiting-product-details --help')
            ->assertSuccessful();
    }

    public function test_dry_run_is_default_and_does_not_mutate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $this->configureShiftAdmin($admin->id);

        $eligible = $this->createCashfreeIncident(
            status: IncidentStatus::AwaitingProductDetails,
            serial: '7881953',
            product: 'MFS110',
        );
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($eligible->order_id);

        $this->createCashfreeIncident(
            status: IncidentStatus::AwaitingProductDetails,
            serial: null,
            product: null,
        );

        Log::spy();

        $this->artisan('cashfree:recover-awaiting-product-details')
            ->expectsOutputToContain('Dry run — no changes will be written')
            ->expectsOutputToContain('scanned: 2')
            ->expectsOutputToContain('eligible: 1')
            ->expectsOutputToContain('promoted (would): 1')
            ->expectsOutputToContain('skipped: 1')
            ->expectsOutputToContain('already-open: 0')
            ->expectsOutputToContain('failures: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::AwaitingProductDetails, $eligible->fresh()->status);
        $this->assertNull($eligible->fresh()->assigned_to_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $eligible->id)
            ->where('event', 'service_case.status_changed')
            ->count());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'cashfree.recover_awaiting_product_details.completed'
                    && ($context['dry_run'] ?? null) === true
                    && ($context['eligible'] ?? null) === 1
                    && ($context['promoted'] ?? null) === 1
                    && ($context['skipped'] ?? null) === 1;
            });

        Carbon::setTestNow();
    }

    public function test_execute_promotes_eligible_incident_and_runs_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createCashfreeIncident(
            status: IncidentStatus::AwaitingProductDetails,
            serial: '7881953',
            product: 'MFS110',
        );
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id);

        Log::spy();

        $this->artisan('cashfree:recover-awaiting-product-details --execute')
            ->expectsOutputToContain('Execute mode')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('eligible: 1')
            ->expectsOutputToContain('promoted: 1')
            ->expectsOutputToContain('skipped: 0')
            ->expectsOutputToContain('failures: 0')
            ->assertSuccessful();

        $fresh = $incident->fresh();

        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->where('new_values->status', IncidentStatus::Open->value)
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.assigned',
            'auditable_id' => $incident->id,
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($incident): bool {
                return $message === 'cashfree.recover_awaiting_product_details.promoted'
                    && ($context['dry_run'] ?? null) === false
                    && ($context['incident_id'] ?? null) === $incident->id;
            });

        Carbon::setTestNow();
    }

    public function test_validation_failure_is_skipped(): void
    {
        $admin = $this->createAdmin();
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createCashfreeIncident(
            status: IncidentStatus::AwaitingProductDetails,
            serial: null,
            product: null,
        );

        $this->artisan('cashfree:recover-awaiting-product-details --execute')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('eligible: 0')
            ->expectsOutputToContain('promoted: 0')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->fresh()->status);
        $this->assertNull($incident->fresh()->assigned_to_user_id);
    }

    public function test_execute_is_idempotent_on_second_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $this->configureShiftAdmin($admin->id);

        $incident = $this->createCashfreeIncident(
            status: IncidentStatus::AwaitingProductDetails,
            serial: '7881953',
            product: 'MFS110',
        );
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order_id);

        $this->artisan('cashfree:recover-awaiting-product-details --execute')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $assigneeId = $incident->fresh()->assigned_to_user_id;

        $this->artisan('cashfree:recover-awaiting-product-details --execute')
            ->expectsOutputToContain('Execute summary')
            ->expectsOutputToContain('scanned: 0')
            ->expectsOutputToContain('eligible: 0')
            ->expectsOutputToContain('promoted: 0')
            ->assertSuccessful();

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatus::Open, $fresh->status);
        $this->assertSame($assigneeId, $fresh->assigned_to_user_id);
        $this->assertSame(0, Incident::query()->where('status', IncidentStatus::AwaitingProductDetails)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->where('new_values->status', IncidentStatus::Open->value)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.assigned')
            ->count());

        Carbon::setTestNow();
    }

    public function test_already_open_incidents_are_ignored(): void
    {
        $admin = $this->createAdmin();
        $this->configureShiftAdmin($admin->id);

        $open = $this->createCashfreeIncident(
            status: IncidentStatus::Open,
            serial: '7881953',
            product: 'MFS110',
        );
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($open->order_id);

        $this->artisan('cashfree:recover-awaiting-product-details --execute')
            ->expectsOutputToContain('scanned: 0')
            ->expectsOutputToContain('eligible: 0')
            ->expectsOutputToContain('promoted: 0')
            ->expectsOutputToContain('already-open: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $open->fresh()->status);
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_id', $open->id)
            ->where('event', 'service_case.status_changed')
            ->count());
    }

    private function createCashfreeIncident(
        IncidentStatus $status,
        ?string $serial,
        ?string $product,
    ): Incident {
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-CF-REC-'.uniqid(),
            'serial_number' => $serial,
            'product_name' => $product,
            'device_model' => $product,
            'cashfree_payment_id' => 'cf_'.uniqid(),
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — '.$order->order_id,
            'description' => 'Automatically created from Cashfree payment webhook. Awaiting product details.',
            'status' => $status,
            'assigned_to_user_id' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function createAutomationActor(): User
    {
        $user = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'ready-admin-'.uniqid().'@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function configureShiftAdmin(int $adminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $adminId,
            'assignment.night_shift_admin_user_id' => (string) $adminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }
}
