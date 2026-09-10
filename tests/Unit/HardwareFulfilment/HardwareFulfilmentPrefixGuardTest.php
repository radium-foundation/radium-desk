<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Tests\TestCase;

class HardwareFulfilmentPrefixGuardTest extends TestCase
{
    public function test_rbp_physical_box_orders_open_hardware_fulfilment(): void
    {
        $request = $this->boxPhysical('RBP1');

        $this->assertTrue(HardwareFulfilmentEligibility::shouldOpenRecord($request));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RBP1'));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeBoxHardwareSourceId('RBP29'));
    }

    public function test_rde_and_rin_remain_hardware(): void
    {
        $rde = $this->boxPhysical('RDE318516');
        $rin = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RdServiceIn,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RIN3460196',
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: ['source_order_type' => 'hardware_direct_buy'],
        );

        $this->assertTrue(HardwareFulfilmentEligibility::shouldOpenRecord($rde));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RDE318516'));
        $this->assertTrue(HardwareFulfilmentEligibility::shouldOpenRecord($rin));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RIN3460196'));
    }

    public function test_rb_rdp_rnp_rsp_do_not_look_like_hardware(): void
    {
        foreach (['RB1', 'RDP1', 'RNP1', 'RSP1', 'RD3511756'] as $id) {
            $this->assertFalse(HardwareFulfilmentEligibility::looksLikeHardwareSourceId($id), $id);
        }
    }

    private function boxPhysical(string $sourceId): ChannelOrderIngestRequest
    {
        return new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: $sourceId,
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
        );
    }

    private function physicalLine(): ChannelOrderLineDraft
    {
        return new ChannelOrderLineDraft(
            description: 'Mantra MFS110 L1',
            qty: 1,
            unitPrice: 118,
            sku: '946',
            shippingLineKind: HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            requiresShipping: true,
            modelId: 946,
        );
    }
}
