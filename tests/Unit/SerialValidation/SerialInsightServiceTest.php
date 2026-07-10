<?php

namespace Tests\Unit\SerialValidation;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialInsightConfidence;
use App\Enums\SerialInsightStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialInsightService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_detects_known_invalid_fm220_serial_pattern(): void
    {
        $order = $this->createOrder('RD-INSIGHT-FM220', 'TC067262100185', 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::Medium, $insight->confidence);
        $this->assertStringContainsString('FM220 pattern', $insight->explanation);
        $this->assertStringContainsString('WhatsApp', (string) $insight->suggestedAction);
        $this->assertStringContainsString('सही serial', (string) $insight->suggestedAction);
    }

    public function test_detects_product_code_submitted_as_serial(): void
    {
        $order = $this->createOrder('RD-INSIGHT-MFS', '54SAXXC5514586', 'MFS 110');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('product code', $insight->explanation);
    }

    public function test_detects_radiumbox_identity_mismatch_when_synced_serial_fails_validation(): void
    {
        $order = $this->createOrder('RD-INSIGHT-RB', 'INVALID-SERIAL', 'Access FM220 L1');
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Suspicious, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertStringContainsString('RadiumBox data', $insight->explanation);
    }

    public function test_valid_serial_returns_high_confidence_valid_status(): void
    {
        $order = $this->createOrder('RD-INSIGHT-VALID', 'M260779805', 'Access FM220 L1');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Valid, $insight->status);
        $this->assertSame(SerialInsightConfidence::High, $insight->confidence);
        $this->assertFalse($insight->isActionable());
    }

    public function test_missing_serial_returns_actionable_missing_insight(): void
    {
        $order = $this->createOrder('RD-INSIGHT-MISSING', null, 'FM220');

        $insight = app(SerialInsightService::class)->analyze($order);

        $this->assertSame(SerialInsightStatus::Missing, $insight->status);
        $this->assertTrue($insight->isActionable());
        $this->assertStringContainsString('WhatsApp', (string) $insight->suggestedAction);
        $this->assertStringContainsString('serial number', (string) $insight->suggestedAction);
    }

    private function createOrder(string $orderId, ?string $serial, string $deviceModel): Order
    {
        $agent = User::factory()->create();

        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serial,
            'product_name' => $deviceModel,
            'device_model' => $deviceModel,
            'customer_name' => 'Insight Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced,
            'created_by' => $agent->id,
        ]);
    }
}
