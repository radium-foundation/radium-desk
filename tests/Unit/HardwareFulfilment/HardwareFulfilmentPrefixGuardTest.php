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
    public function test_paid_rbp_with_physical_lines_opens_hardware_fulfilment(): void
    {
        $request = $this->boxPhysical('RBP1');

        $this->assertTrue(HardwareFulfilmentEligibility::shouldOpenRecord($request));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RBP1'));
    }

    public function test_unpaid_rbp_with_physical_lines_does_not_open_hardware_fulfilment(): void
    {
        $request = $this->boxPhysical('RBP2');
        $request = new ChannelOrderIngestRequest(
            channel: $request->channel,
            sourceType: $request->sourceType,
            sourceId: $request->sourceId,
            lines: $request->lines,
            paymentStatus: 'pending',
            currency: $request->currency,
        );

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
    }

    public function test_rbp_without_physical_lines_does_not_open_hardware_fulfilment(): void
    {
        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RBP3',
            lines: [new ChannelOrderLineDraft(
                description: 'Service only',
                qty: 1,
                unitPrice: 100,
                sku: 'svc',
                shippingLineKind: 'service',
                requiresShipping: false,
                modelId: null,
            )],
            paymentStatus: 'paid',
            currency: 'INR',
        );

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
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
        $rdp = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RdServiceIn,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RDP29',
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: ['source_order_type' => 'hardware_direct_buy'],
        );
        $this->assertTrue(HardwareFulfilmentEligibility::shouldOpenRecord($rdp));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RDP29'));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeRdServiceInHardwareSourceId('RDP29'));
    }

    public function test_rnp_paid_rd_service_net_hardware_opens_fulfilment(): void
    {
        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RdServiceNet,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RNP30',
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: [
                'source' => 'rdservice.net',
                'source_order_type' => 'hardware_direct_buy',
            ],
        );

        $this->assertTrue(HardwareFulfilmentEligibility::shouldOpenRecord($request));
        $this->assertTrue(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RNP30'));
        $this->assertTrue(HardwareFulfilmentEligibility::isRdServiceNetHardwareRequest($request));
    }

    public function test_rb_rsp_service_ids_do_not_look_like_hardware(): void
    {
        foreach (['RB1', 'RSP1', 'RD3511756', 'RN1', 'RA1'] as $id) {
            $this->assertFalse(HardwareFulfilmentEligibility::looksLikeHardwareSourceId($id), $id);
        }
    }

    public function test_rnp_without_hardware_direct_buy_does_not_open_on_net_channel(): void
    {
        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RdServiceNet,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RNP31',
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: ['source' => 'rdservice.net', 'source_order_type' => 'service_renewal'],
        );

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
    }

    public function test_rdp_without_hardware_direct_buy_does_not_open_fulfilment(): void
    {
        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RdServiceIn,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RDP99',
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: ['source_order_type' => 'service_renewal'],
        );

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
        $this->assertFalse(HardwareFulfilmentEligibility::isRdServiceInHardwareRequest($request));
    }

    public function test_cancelled_metadata_blocks_rbp_fulfilment_open(): void
    {
        $request = $this->boxPhysical('RBP920099');
        $request = new ChannelOrderIngestRequest(
            channel: $request->channel,
            sourceType: $request->sourceType,
            sourceId: 'RBP920099',
            lines: $request->lines,
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: ['order_status' => 'cancelled'],
        );

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
    }

    public function test_rdp_hardware_direct_buy_on_box_channel_does_not_open_fulfilment(): void
    {
        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: 'RDP99',
            lines: [$this->physicalLine()],
            paymentStatus: 'paid',
            currency: 'INR',
            metadata: ['source_order_type' => 'hardware_direct_buy'],
        );

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
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
