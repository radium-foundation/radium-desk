<?php

namespace Tests\Unit\LegacyOrder;

use App\Data\LegacyOrderPreview;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\RadiumBox\RadiumBoxOrderSearchResponseMapper;
use App\Services\RdService\RdServiceOrderMapper;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LegacyOrderPreviewRdeMappingTest extends TestCase
{
    public function test_legacy_preview_exposes_rde_product_and_serial_for_import(): void
    {
        $preview = LegacyOrderPreview::fromEnrichment(
            'RDE177816',
            new RadiumBoxOrderEnrichment(
                serialNumber: '10024774',
                deviceModel: 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                customerName: 'Pallab Mukherjee',
                customerPhone: '9874773752',
                legacyOrderStatus: 'Shipped',
            ),
        );

        $this->assertSame('RDE177816', $preview->orderId);
        $this->assertSame('Mantra MFS 100 / 110 L1 Fingerprint Scanner', $preview->productModel);
        $this->assertSame('10024774', $preview->serialNumber);
        $this->assertTrue($preview->isCompleteForOneClick());
        $this->assertSame([], $preview->missingFieldsForOneClick());
    }

    public function test_rde_spoke_payload_maps_full_legacy_preview_contract(): void
    {
        $mapper = new RdServiceOrderMapper(new RadiumBoxOrderSearchResponseMapper);
        $enrichment = $mapper->map($this->rdeSpokePayload(), 'RDE177816');
        $preview = LegacyOrderPreview::fromEnrichment('RDE177816', $enrichment);
        $array = $preview->toArray();

        $this->assertSame('Paid', $preview->paymentStatus);
        $this->assertSame('cashfree', $preview->paymentMethod);
        $this->assertSame('2735', $preview->paymentAmount);
        $this->assertSame('₹2735', $array['payment_amount_display']);
        $this->assertSame('IND568704', $preview->invoiceNumber);
        $this->assertSame('Shipped', $preview->shipmentStatus);
        $this->assertSame('19041860689453', $preview->awb);
        $this->assertSame('MFS 110', $preview->productVariant);
        $this->assertSame('PMTMFS110Z', $preview->productSku);
        $this->assertSame('700150', $preview->deliveryAddress['pincode'] ?? null);
        $this->assertTrue($preview->deliveryAddress['pincode_profile_mismatch'] ?? false);
        $this->assertStringContainsString('order checkout; customer profile PIN differs', (string) $array['delivery_address_display']);
        $this->assertNull($array['payment_reference'] ?? null);
        $this->assertInstanceOf(Carbon::class, $preview->legacyOrderDate);
        $this->assertInstanceOf(Carbon::class, $preview->invoiceDate);
    }

    public function test_rde_spoke_without_payment_reference_does_not_fabricate_values(): void
    {
        $payload = $this->rdeSpokePayload();
        $payload['data']['snapshot']['cashfree_payment_id'] = null;
        $payload['data']['order']['payment_id'] = null;

        $mapper = new RdServiceOrderMapper(new RadiumBoxOrderSearchResponseMapper);
        $preview = LegacyOrderPreview::fromEnrichment(
            'RDE177816',
            $mapper->map($payload, 'RDE177816'),
        );

        $array = $preview->toArray();

        $this->assertArrayNotHasKey('payment_reference', $array);
        $this->assertArrayNotHasKey('payment_date', $array);
        $this->assertNull($array['payment_reference'] ?? null);
    }

    public function test_rde_spoke_without_serial_preserves_null_behavior(): void
    {
        $payload = $this->rdeSpokePayload();
        $payload['data']['rd_order']['serial_no'] = null;
        $payload['data']['snapshot']['serial_number'] = null;

        $mapper = new RdServiceOrderMapper(new RadiumBoxOrderSearchResponseMapper);
        $preview = LegacyOrderPreview::fromEnrichment(
            'RDE177816',
            $mapper->map($payload, 'RDE177816'),
        );

        $this->assertNull($preview->serialNumber);
        $this->assertTrue($preview->isCompleteForOneClick('9874773752'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rdeSpokePayload(): array
    {
        return [
            'status' => 200,
            'spec_version' => '1.0',
            'website_id' => 'radiumbox.com',
            'message' => 'OK',
            'data' => [
                'correlation' => [
                    'rdorderid' => 'RDE177816',
                    'customer_order_id' => 'RDE177816',
                    'cashfree_order_id' => 'RDE177816',
                    'cashfree_payment_id' => null,
                    'orders_id' => 177816,
                    'ordercode' => 'RDE177816',
                ],
                'rd_order' => [
                    'id' => 177816,
                    'rdorderid' => 'RDE177816',
                    'order_id' => 'RDE177816',
                    'product_name' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                    'serial_no' => '10024774',
                    'status' => 'Shipped',
                    'payment_status' => 'Paid',
                    'userdetails' => json_encode([
                        'name' => 'Pallab Mukherjee',
                        'email' => 'ejobfacts2@gmail.com',
                        'phone' => '9874773752',
                    ]),
                ],
                'order' => [
                    'id' => 177816,
                    'ordercode' => 'RDE177816',
                    'invoicecode' => 'IND568704',
                    'payment_status' => 'Paid',
                    'payment_type' => 'cashfree',
                    'total' => '2735',
                    'status' => 'Shipped',
                    'orderdate' => '2026-01-16 21:27:55',
                    'invoice_date' => '2026-01-17 10:20:40',
                ],
                'snapshot' => [
                    'rdorderid' => 'RDE177816',
                    'customer_name' => 'Pallab Mukherjee',
                    'email' => 'ejobfacts2@gmail.com',
                    'phone' => '9874773752',
                    'product' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                    'model' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                    'product_variant' => 'MFS 110',
                    'product_sku' => 'PMTMFS110Z',
                    'serial_number' => '10024774',
                    'payment_status' => 'Paid',
                    'payment_method' => 'cashfree',
                    'payment_amount' => '2735',
                    'rd_order_status' => 'Shipped',
                    'shipment_status' => 'Shipped',
                    'awb' => '19041860689453',
                    'invoice_number' => 'IND568704',
                    'invoice_date' => '2026-01-17 10:20:40',
                    'order_date' => '2026-01-16 21:27:55',
                    'delivery_address' => [
                        'line' => 'Sukanta Sarani R.K.Pally, Sonarpur',
                        'state' => 'West Bengal',
                        'district' => 'South 24 Parganas',
                        'pincode' => '700150',
                        'source' => 'order_checkout_snapshot',
                        'pincode_profile_mismatch' => true,
                    ],
                ],
            ],
        ];
    }
}
