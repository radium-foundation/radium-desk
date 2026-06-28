<?php

namespace Tests\Feature\DataQuality;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\DataQuality\DataQualityEngine;
use App\Infrastructure\DataQuality\DataQualityMetric;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataQualityEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_missing_serial_counts_orders_without_serial_number(): void
    {
        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-DQ-1', null, 'Model A');
        $this->createOrder($agent, 'RD-DQ-2', 'SERIAL-1', 'Model B');

        $result = app(DataQualityEngine::class)->missingSerial();

        $this->assertSame(DataQualityMetric::MissingSerial, $result->metric);
        $this->assertSame(1, $result->count);
    }

    public function test_missing_model_counts_orders_without_device_model(): void
    {
        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-DQ-3', 'SERIAL-2', null);
        $this->createOrder($agent, 'RD-DQ-4', 'SERIAL-3', 'Model C');

        $result = app(DataQualityEngine::class)->missingModel();

        $this->assertSame(1, $result->count);
    }

    public function test_missing_activation_counts_orders_without_transaction_id(): void
    {
        $agent = User::factory()->create();
        $order = $this->createOrder($agent, 'RD-DQ-5', 'SERIAL-4', 'Model D');
        Order::query()->whereKey($order->id)->update(['transaction_id' => 'TXN-1']);

        $this->createOrder($agent, 'RD-DQ-6', 'SERIAL-5', 'Model E');

        $result = app(DataQualityEngine::class)->missingActivation();

        $this->assertSame(1, $result->count);
    }

    public function test_missing_customer_contact_requires_all_contact_fields_empty(): void
    {
        $agent = User::factory()->create();
        $this->createOrder($agent, 'RD-DQ-7', 'SERIAL-6', 'Model F', customerName: null, customerEmail: null, customerPhone: null);
        $this->createOrder($agent, 'RD-DQ-8', 'SERIAL-7', 'Model G', customerName: 'Jane Doe');

        $result = app(DataQualityEngine::class)->missingCustomerContact();

        $this->assertSame(1, $result->count);
    }

    public function test_missing_warranty_uses_sync_store_metadata(): void
    {
        $agent = User::factory()->create();
        $withWarranty = $this->createOrder($agent, 'RD-DQ-9', 'SERIAL-8', 'Model H');
        $withoutWarranty = $this->createOrder($agent, 'RD-DQ-10', 'SERIAL-9', 'Model I');

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markSynced($withWarranty->id, ['warranty' => '2 Years']);
        $syncStore->markSynced($withoutWarranty->id, ['activation_year' => '2024']);

        $result = app(DataQualityEngine::class)->missingWarranty();

        $this->assertSame(1, $result->count);
        $this->assertContains($withoutWarranty->id, $result->orderIds);
    }

    public function test_duplicate_serial_detects_shared_serial_numbers(): void
    {
        $agent = User::factory()->create();
        $first = $this->createOrder($agent, 'RD-DQ-11', 'DUPE-SERIAL', 'Model J');
        $second = $this->createOrder($agent, 'RD-DQ-12', 'DUPE-SERIAL', 'Model K');

        $groups = app(DataQualityEngine::class)->duplicateSerials();
        $result = app(DataQualityEngine::class)->duplicateSerial();

        $this->assertCount(1, $groups);
        $this->assertSame('DUPE-SERIAL', $groups[0]->serialNumber);
        $this->assertSame(2, $result->count);
        $this->assertContains($first->id, $result->orderIds);
        $this->assertContains($second->id, $result->orderIds);
    }

    public function test_all_metrics_returns_every_metric_key(): void
    {
        $metrics = app(DataQualityEngine::class)->allMetrics();

        foreach (DataQualityMetric::cases() as $metric) {
            $this->assertArrayHasKey($metric->value, $metrics);
        }
    }

    private function createOrder(
        User $agent,
        string $orderId,
        ?string $serialNumber,
        ?string $deviceModel,
        ?string $customerName = null,
        ?string $customerEmail = null,
        ?string $customerPhone = null,
    ): Order {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber,
            'device_model' => $deviceModel,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
    }
}
