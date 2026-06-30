<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\Validators\Mfs110SerialValidator;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIdentityValidationAnalyzerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_command_reports_no_failures_when_all_active_orders_pass_validation(): void
    {
        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD-VALID-1',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:analyze-validation')
            ->assertSuccessful()
            ->expectsOutputToContain('No validation failures found among 1 scanned order(s).');
    }

    public function test_command_reports_invalid_mfs110_serial_with_validator_details(): void
    {
        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD3434217',
            'serial_number' => 'ABC123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:analyze-validation --order=RD3434217')
            ->assertSuccessful()
            ->expectsOutputToContain('RD3434217')
            ->expectsOutputToContain(Mfs110SerialValidator::class)
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('MFS110: MFS 110 serial numbers must be numeric only.')
            ->expectsOutputToContain('Validator probably too strict');
    }

    public function test_command_does_not_modify_database_records(): void
    {
        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-READONLY',
            'serial_number' => 'NOT-VALID',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $order = $incident->order;
        $before = [
            'serial_number' => $order->serial_number,
            'product_name' => $order->product_name,
            'device_model' => $order->device_model,
            'audit_log_count' => AuditLog::query()->count(),
        ];

        $this->artisan('orders:analyze-validation --order=RD-READONLY')
            ->assertSuccessful()
            ->expectsOutputToContain('FAIL');

        $order->refresh();

        $this->assertSame($before['serial_number'], $order->serial_number);
        $this->assertSame($before['product_name'], $order->product_name);
        $this->assertSame($before['device_model'], $order->device_model);
        $this->assertSame($before['audit_log_count'], AuditLog::query()->count());
    }

    public function test_failed_only_excludes_missing_serial_orders(): void
    {
        $actor = User::factory()->create();

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-MISSING-SERIAL',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-INVALID-SERIAL',
            'serial_number' => 'ABC123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:analyze-validation --failed-only')
            ->assertSuccessful()
            ->expectsOutputToContain('RD-INVALID-SERIAL')
            ->doesntExpectOutputToContain('RD-MISSING-SERIAL');
    }

    public function test_limit_option_restricts_scanned_orders(): void
    {
        $actor = User::factory()->create();

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-LIMIT-1',
            'serial_number' => 'ABC123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-LIMIT-2',
            'serial_number' => 'XYZ789',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:analyze-validation --limit=1')
            ->assertSuccessful()
            ->expectsOutputToContain('Orders scanned: 1');
    }

    public function test_command_reports_duplicate_serial_conflict(): void
    {
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
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:analyze-validation --order=RD-DUPLICATE')
            ->assertSuccessful()
            ->expectsOutputToContain('Duplicate serial conflict')
            ->expectsOutputToContain('This serial number belongs to a different order.')
            ->expectsOutputToContain('Duplicate serial');
    }

    public function test_command_recommends_radiumbox_invalid_identity_when_synced_serial_fails_validation(): void
    {
        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-RB-INVALID',
            'serial_number' => 'ABC123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($incident->order->id);

        $this->artisan('orders:analyze-validation --order=RD-RB-INVALID')
            ->assertSuccessful()
            ->expectsOutputToContain('Synced')
            ->expectsOutputToContain('RadiumBox returned invalid identity');
    }

    public function test_command_reports_grouped_statistics_and_top_serial_patterns(): void
    {
        $actor = User::factory()->create();

        foreach (['RD-STAT-1', 'RD-STAT-2'] as $orderId) {
            $this->createActiveIncident($actor, [
                'order_id' => $orderId,
                'serial_number' => 'FPSPL1141XX',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
            ]);
        }

        $this->createActiveIncident($actor, [
            'order_id' => 'RD-STAT-3',
            'serial_number' => 'SN123456',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        $this->artisan('orders:analyze-validation')
            ->assertSuccessful()
            ->expectsOutputToContain('Validation analysis summary')
            ->expectsOutputToContain('Failures found: 3')
            ->expectsOutputToContain('MFS 110 (3 orders)')
            ->expectsOutputToContain('FPSPL1141XX (2 orders)')
            ->expectsOutputToContain('SN123456 (1 orders)');
    }

    public function test_command_reports_radiumbox_not_found_category(): void
    {
        $actor = User::factory()->create();
        $incident = $this->createActiveIncident($actor, [
            'order_id' => 'RD-NOT-FOUND',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markFailed(
            $incident->order->id,
            'Order was not found in RadiumBox.',
            ['lookup_result' => 'order_not_found'],
        );

        $this->artisan('orders:analyze-validation --order=RD-NOT-FOUND')
            ->assertSuccessful()
            ->expectsOutputToContain('Failed')
            ->expectsOutputToContain('Order was not found in RadiumBox.')
            ->expectsOutputToContain('RadiumBox not found');
    }

    public function test_command_skips_orders_without_active_incidents(): void
    {
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-CLOSED',
            'serial_number' => 'ABC123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed case',
            'description' => 'Done.',
            'status' => IncidentStatus::Closed,
            'created_by' => $actor->id,
        ]);

        $this->artisan('orders:analyze-validation')
            ->assertSuccessful()
            ->expectsOutputToContain('No validation failures found among 0 scanned order(s).');
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
            'title' => 'Validation analyzer test',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
    }
}
