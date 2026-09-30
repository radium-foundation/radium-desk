<?php

namespace Tests\Unit;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\AutomationOperationsValidationCollector;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationOperationsValidationCollectorSyncLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, array{0: RadiumBoxEnrichmentSyncStatus, 1: string}>
     */
    public static function extendedSyncStatusLabels(): array
    {
        return [
            'handoff pending' => [RadiumBoxEnrichmentSyncStatus::HandoffPending, 'Handoff Pending'],
            'handoff failed' => [RadiumBoxEnrichmentSyncStatus::HandoffFailed, 'Handoff Failed'],
            'reconciliation required' => [RadiumBoxEnrichmentSyncStatus::ReconciliationRequired, 'Reconciliation Required'],
        ];
    }

    #[DataProvider('extendedSyncStatusLabels')]
    public function test_collect_from_orders_labels_extended_radiumbox_sync_statuses(
        RadiumBoxEnrichmentSyncStatus $syncStatus,
        string $expectedLabel,
    ): void {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-'.$syncStatus->value,
            'serial_number' => 'DUPLICATE-'.$syncStatus->value,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'radiumbox_sync_status' => $syncStatus,
            'created_by' => $actor->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD-SYNC-OTHER-'.$syncStatus->value,
            'serial_number' => 'DUPLICATE-'.$syncStatus->value,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $analysis = app(AutomationOperationsValidationCollector::class)
            ->collectFromOrders([$order])
            ->failures[0];

        $this->assertSame($expectedLabel, $analysis->radiumBoxSyncLabel);
    }
}
