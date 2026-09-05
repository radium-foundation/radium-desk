<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\SettingService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillReadyQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-27 12:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.admin_fallback_enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('radiumbox:backfill-ready-queue --help')
            ->assertSuccessful();

        $this->artisan('readyqueue:backfill --help')
            ->assertSuccessful();
    }

    public function test_dry_run_dispatches_nothing_and_reports_counts(): void
    {
        Log::spy();
        Queue::fake();

        $this->createMissingEnrichmentCase('RD-RQ-BACKFILL-1');
        $this->createValidatedUnassignedCase('RD-RQ-BACKFILL-2');

        $this->artisan('radiumbox:backfill-ready-queue --dry-run')
            ->expectsOutputToContain('cases scanned: 2')
            ->expectsOutputToContain('cases would process: 2')
            ->expectsOutputToContain('cases skipped: 0')
            ->expectsOutputToContain('cases failed: 0')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Ready Queue backfill completed.'
                    && ($context['dry_run'] ?? null) === true
                    && ($context['would_process'] ?? null) === 2
                    && ($context['processed'] ?? null) === 0;
            });
    }

    public function test_dispatches_enrichment_for_orders_missing_serial(): void
    {
        Queue::fake();

        $order = $this->createMissingEnrichmentCase('RD-RQ-ENRICH')->order;

        $this->artisan('radiumbox:backfill-ready-queue --limit=10')
            ->expectsOutputToContain('cases processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_skips_non_stale_pending_enrichment(): void
    {
        Queue::fake();
        Log::spy();

        $incident = $this->createMissingEnrichmentCase('RD-RQ-PENDING');
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($incident->order_id);

        $this->assertSame(0, Artisan::call('radiumbox:backfill-ready-queue'));
        $this->assertStringContainsString('cases processed: 0', Artisan::output());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($incident): bool {
                return $message === 'Ready Queue backfill skipped case.'
                    && ($context['reason'] ?? null) === 'enrichment_already_pending'
                    && ($context['incident_id'] ?? null) === $incident->id;
            })
            ->atLeast()
            ->once();

        Queue::assertNothingPushed();
    }

    public function test_retries_stale_pending_enrichment(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-27 12:00:00');

        $incident = $this->createMissingEnrichmentCase('RD-RQ-STALE');
        $order = $incident->order;
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markPending($order->id);

        Carbon::setTestNow('2026-07-27 13:00:00');

        $this->artisan('radiumbox:backfill-ready-queue')
            ->expectsOutputToContain('cases processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_evaluates_eligibility_for_validated_unassigned_case(): void
    {
        Queue::fake();

        [$admin, $incident, $order] = $this->createValidatedUnassignedCase('RD-RQ-READY');

        app(SettingService::class)->setMany([
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
        ]);

        $this->artisan('radiumbox:backfill-ready-queue')
            ->expectsOutputToContain('cases processed: 1')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $fresh = $incident->fresh(['assignee.roles', 'order']);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)
                ->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($fresh),
        );
    }

    public function test_skips_already_assigned_ready_queue_admin_and_is_idempotent(): void
    {
        Queue::fake();
        Log::spy();

        [$admin, $incident] = $this->createValidatedUnassignedCase('RD-RQ-IDEMP');
        $incident->update(['assigned_to_user_id' => $admin->id]);

        $this->assertSame(0, Artisan::call('radiumbox:backfill-ready-queue'));
        $this->assertStringContainsString('cases processed: 0', Artisan::output());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($incident): bool {
                return $message === 'Ready Queue backfill skipped case.'
                    && ($context['reason'] ?? null) === 'already_in_ready_queue'
                    && ($context['incident_id'] ?? null) === $incident->id;
            })
            ->atLeast()
            ->once();

        $this->assertSame(0, Artisan::call('radiumbox:backfill-ready-queue'));
        $this->assertStringContainsString('cases processed: 0', Artisan::output());

        Queue::assertNothingPushed();
        $this->assertSame($admin->id, $incident->fresh()->assigned_to_user_id);
    }

    public function test_skips_expired_grace_for_process_automation_pending(): void
    {
        Queue::fake();
        Log::spy();

        [, $incident] = $this->createValidatedUnassignedCase('RD-RQ-GRACE');
        $incident->update([
            'automation_pending_until' => now()->subMinute(),
        ]);

        $this->assertSame(0, Artisan::call('radiumbox:backfill-ready-queue'));
        $this->assertStringContainsString('cases processed: 0', Artisan::output());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($incident): bool {
                return $message === 'Ready Queue backfill skipped case.'
                    && ($context['reason'] ?? null) === 'awaiting_grace_processor'
                    && ($context['incident_id'] ?? null) === $incident->id;
            })
            ->atLeast()
            ->once();

        Queue::assertNothingPushed();
        $this->assertNull($incident->fresh()->assigned_to_user_id);
    }

    public function test_respects_limit(): void
    {
        Queue::fake();

        $this->createMissingEnrichmentCase('RD-RQ-LIMIT-1');
        $this->createMissingEnrichmentCase('RD-RQ-LIMIT-2');
        $this->createMissingEnrichmentCase('RD-RQ-LIMIT-3');

        $this->artisan('radiumbox:backfill-ready-queue --limit=2')
            ->expectsOutputToContain('cases processed: 2')
            ->assertSuccessful();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 2);
    }

    public function test_production_recover_queues_is_registered_and_dry_runs(): void
    {
        $this->artisan('production:recover-queues --dry-run --limit=5 --chunk=10 --skip-repairs')
            ->expectsOutputToContain('Production queue recovery (dry run)')
            ->assertSuccessful();
    }

    private function createMissingEnrichmentCase(string $orderId): Incident
    {
        $admin = $this->adminUser();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'device_model' => null,
            'product_name' => null,
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $admin->id,
            'cashfree_payment_id' => 'cf_'.$orderId,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Missing enrichment {$orderId}",
            'description' => 'Needs RadiumBox enrichment.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createValidatedUnassignedCase(string $orderId): array
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $admin->id,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Validated unassigned {$orderId}",
            'description' => 'Should enter Ready Queue.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->passesValidationForOrder($order->fresh()),
        );

        return [$admin, $incident, $order];
    }

    private function adminUser(): User
    {
        $admin = User::query()->where('email', 'admin@radium.local')->first();

        if ($admin === null) {
            $admin = User::factory()->create([
                'email' => 'admin@radium.local',
                'is_active' => true,
            ]);
            $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        if (User::query()->where('email', 'superadmin@radium.local')->doesntExist()) {
            $superadmin = User::factory()->create([
                'email' => 'superadmin@radium.local',
                'is_active' => true,
            ]);
            $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        }

        return $admin;
    }
}
