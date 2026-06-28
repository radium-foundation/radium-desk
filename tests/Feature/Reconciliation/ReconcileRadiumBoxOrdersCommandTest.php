<?php

namespace Tests\Feature\Reconciliation;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\Reconciliation\OrderReconciliationService;
use App\Infrastructure\Reconciliation\ReconciliationCsvExporter;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReconcileRadiumBoxOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('radiumbox:reconcile --help')
            ->assertSuccessful();
    }

    public function test_command_outputs_reconciliation_metrics(): void
    {
        Log::spy();

        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-REC-1', null, null);
        $this->createOrder($agent, 'RD-REC-2', 'SERIAL-1', 'Model A');

        $this->artisan('radiumbox:reconcile')
            ->expectsOutputToContain('Total Orders')
            ->expectsOutputToContain('Orders Missing Serial')
            ->expectsOutputToContain('Integration health snapshot')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'RadiumBox order reconciliation completed.'
                    && ($context['total_orders'] ?? null) === 2
                    && ($context['orders_missing_serial'] ?? null) === 1;
            });
    }

    public function test_csv_option_exports_order_rows(): void
    {
        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD-REC-CSV', 'SERIAL-CSV', 'Model CSV', customerName: 'Customer One');
        $order->update(['serial_entered_by_user_id' => $agent->id]);

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markFailed($order->id, 'API timeout');

        $csvPath = storage_path('app/reconciliation-test.csv');

        try {
            $this->artisan('radiumbox:reconcile --csv='.$csvPath)
                ->expectsOutputToContain('CSV export written')
                ->assertSuccessful();

            $this->assertFileExists($csvPath);

            $contents = file_get_contents($csvPath);
            $this->assertIsString($contents);
            $this->assertStringContainsString('Order ID', $contents);
            $this->assertStringContainsString('RD-REC-CSV', $contents);
            $this->assertStringContainsString('Customer One', $contents);
            $this->assertStringContainsString('FAILED', $contents);
            $this->assertStringContainsString('Serial', $contents);
        } finally {
            if (is_file($csvPath)) {
                unlink($csvPath);
            }
        }
    }

    public function test_reconciliation_service_tracks_sync_status_counts(): void
    {
        $agent = User::factory()->create();
        $pending = $this->createOrder($agent, 'RD-REC-PENDING', null, null);
        $failed = $this->createOrder($agent, 'RD-REC-FAILED', 'SERIAL-F', 'Model F');
        $synced = $this->createOrder($agent, 'RD-REC-SYNCED', 'SERIAL-S', 'Model S');

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markPending($pending->id);
        $syncStore->markFailed($failed->id, 'Lookup failed');
        $syncStore->markSynced($synced->id);

        $report = app(OrderReconciliationService::class)->report();

        $this->assertSame(3, $report->totalOrders);
        $this->assertSame(1, $report->ordersAwaitingSync);
        $this->assertSame(1, $report->ordersWithFailedSync);
        $this->assertSame(1, $report->ordersSuccessfullySynced);
    }

    public function test_csv_exporter_includes_expected_headers(): void
    {
        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD-REC-ROW', 'SERIAL-R', 'Model R');

        $rows = app(OrderReconciliationService::class)->orderRows();
        $csv = app(ReconciliationCsvExporter::class)->export($rows);

        $this->assertStringContainsString('Order ID', $csv);
        $this->assertStringContainsString('Sync Status', $csv);
        $this->assertStringContainsString('Manual Override', $csv);
        $this->assertStringContainsString('RD-REC-ROW', $csv);
        $this->assertCount(1, $rows);
        $this->assertSame('RD-REC-ROW', $rows[0]->orderId);
    }

    private function createOrder(
        User $agent,
        string $orderId,
        ?string $serialNumber,
        ?string $deviceModel,
        ?string $customerName = null,
    ): Order {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber,
            'device_model' => $deviceModel,
            'customer_name' => $customerName,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    }
}
