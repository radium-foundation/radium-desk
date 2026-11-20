<?php

namespace Tests\Feature\Shipping;

use App\Enums\CommerceOrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Shipping\HttpShiprocketGateway;
use App\Services\Shipping\ShiprocketApiAuditContext;
use App\Services\Shipping\ShiprocketApiAuditor;
use App\Services\Shipping\ShiprocketApiAuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiprocketApiAuditGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.enabled' => false,
            'shipping.provider' => 'none',
            'shipping.http_enabled' => false,
            'shipping.base_url' => 'https://apiv2.shiprocket.in/v1/external',
            'shipping.api_email' => 'ship@example.test',
            'shipping.api_password' => 'secret',
            'shipping.timeout_seconds' => 2,
            'shipping.connect_timeout_seconds' => 1,
            'shipping.token_ttl_seconds' => 86400,
            'shipping.token_expiry_margin_seconds' => 120,
            'shipping.auth_connect_retries' => 1,
        ]);

        Cache::flush();
        ShiprocketApiAuditScope::resetForTesting();
    }

    public function test_gateway_assign_awb_writes_sanitized_shipment_event(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-audit'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
                'status_code' => 200,
                'message' => 'AWB assigned',
                'response' => [
                    'data' => [
                        'awb_code' => '284931180089754',
                        'courier_company_id' => 252,
                    ],
                ],
            ], 200),
        ]);

        $shipment = $this->createShipment('99001');
        $auditor = new ShiprocketApiAuditor;
        $gateway = new HttpShiprocketGateway($auditor);

        $result = ShiprocketApiAuditScope::run(
            ShiprocketApiAuditContext::fromShipment($shipment),
            fn () => $gateway->assignAwb('99001', 252),
        );

        $this->assertSame('assigned', $result->status);

        $event = ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('activity', 'shiprocket_api:assign_awb')
            ->sole();

        $this->assertSame('shiprocket_api_audit_v1', $event->payload['schema'] ?? null);
        $this->assertSame('/courier/assign/awb', $event->payload['endpoint'] ?? null);
        $this->assertSame('success', $event->payload['outcome'] ?? null);
        $this->assertGreaterThan(0, $event->payload['duration_ms'] ?? 0);
        $this->assertSame('284931180089754', $event->payload['awb_code'] ?? null);
    }

    public function test_gateway_reassign_courier_awb_writes_sanitized_shipment_event(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-audit'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
                'awb_assign_status' => 1,
                'response' => [
                    'data' => [
                        'awb_code' => '284931180089755',
                        'courier_company_id' => 252,
                    ],
                ],
            ], 200),
        ]);

        $shipment = $this->createShipment('99002');
        $auditor = new ShiprocketApiAuditor;
        $gateway = new HttpShiprocketGateway($auditor);

        $result = ShiprocketApiAuditScope::run(
            ShiprocketApiAuditContext::fromShipment($shipment),
            fn () => $gateway->reassignCourierAwb('99002', 252),
        );

        $this->assertSame('reassigned', $result->status);

        $event = ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('activity', 'shiprocket_api:reassign_courier_awb')
            ->sole();

        $this->assertSame('/courier/assign/awb', $event->payload['endpoint'] ?? null);
        $this->assertSame('success', $event->payload['outcome'] ?? null);
    }

    public function test_auth_login_is_not_audited(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-audit'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/track/awb/77191051976' => Http::response([
                'tracking_data' => [
                    'shipment_status' => 'CANCELED',
                ],
            ], 200),
        ]);

        $shipment = $this->createShipment('99002', '77191051976');
        $gateway = new HttpShiprocketGateway(new ShiprocketApiAuditor);

        ShiprocketApiAuditScope::run(
            ShiprocketApiAuditContext::fromShipment($shipment),
            fn () => $gateway->trackByAwb('77191051976'),
        );

        $this->assertNull(
            ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('activity', 'like', 'shiprocket_api:%login%')
                ->first(),
        );

        $this->assertNotNull(
            ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('activity', 'shiprocket_api:track_awb')
                ->first(),
        );
    }

    private function createShipment(string $externalShipmentId, ?string $awb = null): Shipment
    {
        $sourceId = 'AUD'.$externalShipmentId;
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
            'shipment_no' => 'RBP'.$externalShipmentId,
            'commerce_order_id' => $commerce->id,
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::Created,
            'external_order_id' => 'order-'.$externalShipmentId,
            'external_shipment_id' => $externalShipmentId,
            'awb' => $awb,
            'idempotency_key' => 'hardware:shiprocket:create:'.$sourceId,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
