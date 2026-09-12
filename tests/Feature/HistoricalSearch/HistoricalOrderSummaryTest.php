<?php

namespace Tests\Feature\HistoricalSearch;

use App\Models\User;
use App\Services\HistoricalSearch\HistoricalOrderSummaryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class HistoricalOrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'historical_search.enabled' => true,
            'historical_search.connection' => 'radium_hist',
        ]);
    }

    public function test_summary_service_returns_order_context(): void
    {
        $service = $this->makeSummaryService(paymentStatus: 'paid');

        $summary = $service->forOrderId(42);

        $this->assertNotNull($summary);
        $this->assertSame('RD3191323', $summary->orderId);
        $this->assertSame(2019, $summary->orderYear);
        $this->assertSame('paid', $summary->paymentDisplay);
        $this->assertSame('INV-771', $summary->invoiceNumber);
        $this->assertSame('1091311852843', $summary->awb);
        $this->assertSame('MFS 110 (946)', $summary->productModel);
        $this->assertSame('1311I252197', $summary->serialNumber);
        $this->assertTrue($summary->isHistoricalOnly);
    }

    public function test_unverified_payment_does_not_render_paid(): void
    {
        $service = $this->makeSummaryService(paymentStatus: 'pending');

        $summary = $service->forOrderId(42);

        $this->assertNotNull($summary);
        $this->assertSame('unpaid', $summary->paymentDisplay);
    }

    public function test_historical_search_alone_does_not_create_service_case(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3191323']))
            ->assertOk();

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    private function makeSummaryService(string $paymentStatus): HistoricalOrderSummaryService
    {
        $connection = Mockery::mock(Connection::class);

        DB::partialMock()
            ->shouldReceive('connection')
            ->with('radium_hist')
            ->andReturn($connection);

        $connection->shouldReceive('statement')->andReturn(true);

        $orderBuilder = Mockery::mock(Builder::class);
        $invoiceBuilder = Mockery::mock(Builder::class);
        $shipmentBuilder = Mockery::mock(Builder::class);
        $itemBuilder = Mockery::mock(Builder::class);
        $serialBuilder = Mockery::mock(Builder::class);
        $provenanceBuilder = Mockery::mock(Builder::class);

        $connection->shouldReceive('table')->with('hist_order')->andReturn($orderBuilder);
        $connection->shouldReceive('table')->with('hist_invoice')->andReturn($invoiceBuilder);
        $connection->shouldReceive('table')->with('hist_shipment')->andReturn($shipmentBuilder);
        $connection->shouldReceive('table')->with('hist_order_item')->andReturn($itemBuilder);
        $connection->shouldReceive('table')->with('hist_serial')->andReturn($serialBuilder);
        $connection->shouldReceive('table')->with('hist_provenance')->andReturn($provenanceBuilder);

        $orderBuilder->shouldReceive('select')->andReturnSelf();
        $orderBuilder->shouldReceive('where')->andReturnSelf();
        $orderBuilder->shouldReceive('orderByDesc')->andReturnSelf();
        $orderBuilder->shouldReceive('first')->andReturn((object) [
            'id' => 42,
            'public_code' => 'RD3191323',
            'order_date' => '2019-03-12',
            'payment_status' => $paymentStatus,
            'total_amount' => '12999.00',
            'currency' => 'INR',
            'order_lineage' => 'commerce_active',
            'status' => 'completed',
        ]);

        $invoiceBuilder->shouldReceive('where')->andReturnSelf();
        $invoiceBuilder->shouldReceive('orderByDesc')->andReturnSelf();
        $invoiceBuilder->shouldReceive('value')->with('invoice_number')->andReturn('INV-771');

        $shipmentBuilder->shouldReceive('where')->andReturnSelf();
        $shipmentBuilder->shouldReceive('whereNotNull')->andReturnSelf();
        $shipmentBuilder->shouldReceive('orderByDesc')->andReturnSelf();
        $shipmentBuilder->shouldReceive('value')->with('awb')->andReturn('1091311852843');

        $itemBuilder->shouldReceive('select')->andReturnSelf();
        $itemBuilder->shouldReceive('where')->andReturnSelf();
        $itemBuilder->shouldReceive('orderBy')->andReturnSelf();
        $itemBuilder->shouldReceive('first')->andReturn((object) [
            'product_name' => 'MFS 110',
            'product_ref' => '946',
        ]);

        $serialBuilder->shouldReceive('where')->andReturnSelf();
        $serialBuilder->shouldReceive('orderByDesc')->andReturnSelf();
        $serialBuilder->shouldReceive('value')->with('serial_number')->andReturn('1311I252197');

        $provenanceBuilder->shouldReceive('select')->andReturnSelf();
        $provenanceBuilder->shouldReceive('where')->andReturnSelf();
        $provenanceBuilder->shouldReceive('orderBy')->andReturnSelf();
        $provenanceBuilder->shouldReceive('limit')->andReturnSelf();
        $provenanceBuilder->shouldReceive('get')->andReturn(collect([
            (object) [
                'source_lineage' => 'commerce_active',
                'source_database' => 'radium_old_final',
                'source_table' => 'orders',
                'source_pk' => '99123',
            ],
        ]));

        return app(HistoricalOrderSummaryService::class);
    }
}
