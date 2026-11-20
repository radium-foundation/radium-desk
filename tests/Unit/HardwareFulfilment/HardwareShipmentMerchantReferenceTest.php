<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Services\HardwareFulfilment\HardwareShipmentMerchantReference;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HardwareShipmentMerchantReferenceTest extends TestCase
{
    #[Test]
    public function test_new_shipment_reference_uses_source_id_without_prefix(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP2281']);

        $this->assertSame('RBP2281', HardwareShipmentMerchantReference::forNewShipment($fulfilment));
    }

    #[Test]
    public function test_search_includes_stored_legacy_clean_and_alternate(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP2281']);
        $shipment = new Shipment(['shipment_no' => 'HW-RBP2281']);

        $refs = HardwareShipmentMerchantReference::searchReferences($shipment, $fulfilment);

        $this->assertSame(['HW-RBP2281', 'RBP2281'], $refs);
    }

    #[Test]
    public function test_search_for_clean_stored_row_includes_legacy_fallback(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP2310']);
        $shipment = new Shipment(['shipment_no' => 'RBP2310']);

        $refs = HardwareShipmentMerchantReference::searchReferences($shipment, $fulfilment);

        $this->assertSame(['RBP2310', 'HW-RBP2310'], $refs);
    }

    #[Test]
    public function test_accepts_merchant_id_for_either_format(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP2281']);
        $shipment = new Shipment(['shipment_no' => 'RBP2281']);

        $this->assertTrue(HardwareShipmentMerchantReference::acceptsMerchantOrderId('RBP2281', $shipment, $fulfilment));
        $this->assertTrue(HardwareShipmentMerchantReference::acceptsMerchantOrderId('HW-RBP2281', $shipment, $fulfilment));
        $this->assertFalse(HardwareShipmentMerchantReference::acceptsMerchantOrderId('RBP9999', $shipment, $fulfilment));
    }
}
