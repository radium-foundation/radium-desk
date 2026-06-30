<?php

namespace Tests\Feature;

use App\Enums\OrderIdentityRepairFailureCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\OrderIdentityRepairService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderIdentityRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_command_repairs_missing_identity_from_radiumbox(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-1',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('Orders repaired: 1')
            ->expectsOutputToContain('RD-REPAIR-1');

        $order = $incident->order->fresh();

        $this->assertSame('7881953', $order->serial_number);
        $this->assertSame('MFS 110', $order->device_model);
        $this->assertSame('MFS 110', $order->product_name);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id));

        $this->assertDatabaseHas('audit_logs', [
            'event' => OrderIdentityRepairService::AUDIT_EVENT,
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    public function test_command_repairs_invalid_serial_with_radiumbox_identity(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-2',
            'serial_number' => 'NOT-VALID',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:repair-identity --force')->assertSuccessful();

        $order = $incident->order->fresh();
        $this->assertSame('7881953', $order->serial_number);
    }

    public function test_command_repairs_placeholder_product_without_overwriting_valid_serial(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '9999999',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-PLACEHOLDER',
            'serial_number' => '7881953',
            'product_name' => 'UNKNOWN',
            'device_model' => null,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markFailed(
            $incident->order->id,
            'Awaiting authoritative product details.',
        );

        $this->artisan('orders:repair-identity --force')->assertSuccessful();

        $order = $incident->order->fresh();
        $this->assertSame('7881953', $order->serial_number);
        $this->assertSame('MFS 110', $order->device_model);
        $this->assertSame('MFS 110', $order->product_name);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-DRY',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('RD-REPAIR-DRY');

        $order = $incident->order->fresh();
        $this->assertNull($order->serial_number);
        $this->assertNull($order->device_model);
        $this->assertNull(app(RadiumBoxOrderEnrichmentSyncStore::class)->status($order->id));

        $this->assertSame(0, AuditLog::query()->where('event', OrderIdentityRepairService::AUDIT_EVENT)->count());
    }

    public function test_command_is_idempotent_for_already_valid_orders(): void
    {
        Http::fake();

        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-VALID',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order->id);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('No orders require identity repair.');

        Http::assertNothingSent();
    }

    public function test_command_respects_limit_option(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();

        $first = $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-LIMIT-1',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-LIMIT-2',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force --limit=1')
            ->assertSuccessful()
            ->expectsOutputToContain('Orders repaired: 1');

        $this->assertSame('7881953', $first->order->fresh()->serial_number);
        $this->assertNull(Order::query()->where('order_id', 'RD-REPAIR-LIMIT-2')->value('serial_number'));
    }

    public function test_second_run_skips_repaired_order(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD-REPAIR-IDEMPOTENT',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force')->assertSuccessful();

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('No orders require identity repair.');
    }

    public function test_active_only_limits_scope_to_orders_with_active_incidents(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-NO-INCIDENT',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-WITH-INCIDENT',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force --active-only')
            ->assertSuccessful()
            ->expectsOutputToContain('Orders repaired: 1');

        $this->assertSame('7881953', $incident->order->fresh()->serial_number);
        $this->assertNull(Order::query()->where('order_id', 'RD-NO-INCIDENT')->value('serial_number'));
    }

    public function test_default_scope_repairs_orders_without_active_incidents(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-LEGACY-ONLY',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('Orders repaired: 1');

        $this->assertSame('7881953', Order::query()->where('order_id', 'RD-LEGACY-ONLY')->value('serial_number'));
    }

    public function test_command_prompts_for_confirmation_without_force(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD-CONFIRM',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity')
            ->expectsConfirmation('You are about to update 1 historical order(s). Continue?', 'no')
            ->assertSuccessful()
            ->expectsOutputToContain('Repair cancelled.');

        $this->assertNull(Order::query()->where('order_id', 'RD-CONFIRM')->value('serial_number'));
    }

    public function test_needs_radiumbox_fetch_returns_false_for_complete_valid_identity(): void
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-NO-FETCH',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $service = app(OrderIdentityRepairService::class);

        $this->assertFalse($service->needsRadiumBoxFetch($order));
        $this->assertTrue(app(\App\Services\ServiceCaseAssignmentEligibilityService::class)->passesValidationForOrder($order));
    }

    public function test_command_reports_radiumbox_not_found_failure(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 404,
                'message' => 'Order not found',
            ], 404),
        ]);

        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD-NOT-FOUND',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('Failed Orders')
            ->expectsOutputToContain('RD-NOT-FOUND')
            ->expectsOutputToContain('Reason: RadiumBox not found:');
    }

    public function test_command_reports_api_timeout_failure(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out.'),
        ]);

        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD-TIMEOUT',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('Failed Orders')
            ->expectsOutputToContain('RD-TIMEOUT')
            ->expectsOutputToContain('Reason: API timeout:');
    }

    public function test_command_reports_duplicate_serial_failure(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-OWNER',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-DUPLICATE',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $summary = app(OrderIdentityRepairService::class)->repair(dryRun: false, activeOnly: false);

        $failure = collect($summary->failedOrders)->first(
            fn ($failure) => $failure->orderId === 'RD-DUPLICATE',
        );

        $this->assertNotNull($failure);
        $this->assertSame(OrderIdentityRepairFailureCategory::DuplicateSerial, $failure->category);
        $this->assertStringContainsString('7881953', $failure->message);
        $this->assertStringContainsString('RD-OWNER', $failure->message);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('Failed Orders')
            ->expectsOutputToContain('RD-DUPLICATE')
            ->expectsOutputToContain('Reason: Duplicate serial:');
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     */
    private function createActiveIncident(User $actor, array $orderAttributes): Incident
    {
        $order = Order::query()->create([
            'status' => 'active',
            'created_by' => $actor->id,
            ...$orderAttributes,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Identity repair test',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
    }
}
