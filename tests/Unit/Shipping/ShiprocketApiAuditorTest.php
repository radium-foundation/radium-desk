<?php

namespace Tests\Unit\Shipping;

use App\Enums\CommerceOrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Shipping\ShiprocketApiAuditContext;
use App\Services\Shipping\ShiprocketApiAuditor;
use App\Services\Shipping\ShiprocketApiAuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiprocketApiAuditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ShiprocketApiAuditScope::resetForTesting();
    }

    public function test_persists_shipment_event_with_allowlisted_payload(): void
    {
        $shipment = $this->createShipment();

        ShiprocketApiAuditScope::run(
            ShiprocketApiAuditContext::fromShipment($shipment),
            function (): void {
                (new ShiprocketApiAuditor)->recordOperation(
                    'assign_awb',
                    [
                        'method' => 'POST',
                        'path' => '/courier/assign/awb',
                        'status' => 200,
                        'json' => [
                            'response' => [
                                'data' => [
                                    'awb_code' => 'AWB998877',
                                    'courier_company_id' => 252,
                                ],
                            ],
                            'billing_phone' => '9999999999',
                        ],
                    ],
                    microtime(true) - 0.05,
                    'assigned',
                );
            },
        );

        $event = ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('activity', 'shiprocket_api:assign_awb')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('shiprocket_api', $event->source);
        $this->assertSame('AWB998877', $event->payload['awb_code'] ?? null);
        $this->assertArrayNotHasKey('billing_phone', $event->payload);
        $this->assertSame('success', $event->payload['outcome'] ?? null);
    }

    public function test_audit_persistence_failure_does_not_throw(): void
    {
        $shipment = $this->createShipment();

        ShipmentEvent::creating(static function (): void {
            throw new \RuntimeException('simulated audit store failure');
        });

        ShiprocketApiAuditScope::run(
            ShiprocketApiAuditContext::fromShipment($shipment),
            function (): void {
                (new ShiprocketApiAuditor)->recordOperation(
                    'track_awb',
                    [
                        'method' => 'GET',
                        'path' => '/courier/track/awb/77191051976',
                        'status' => 200,
                        'json' => ['tracking_data' => ['shipment_status' => 'PICKED UP']],
                    ],
                    microtime(true),
                    'available',
                );
            },
        );

        $this->assertSame(
            0,
            ShipmentEvent::query()->where('shipment_id', $shipment->id)->count(),
        );

        ShipmentEvent::flushEventListeners();
    }

    private function createShipment(): Shipment
    {
        $sourceId = 'P2040'.random_int(1000, 9999);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
        ]);

        return Shipment::query()->create([
            'shipment_no' => 'HW-'.$sourceId,
            'commerce_order_id' => $commerce->id,
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::Created,
            'external_order_id' => 'ext-order-'.$sourceId,
            'external_shipment_id' => 'ext-ship-'.$sourceId,
            'awb' => null,
            'idempotency_key' => 'hardware:shiprocket:create:'.$sourceId,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
