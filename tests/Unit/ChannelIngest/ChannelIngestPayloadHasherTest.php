<?php

namespace Tests\Unit\ChannelIngest;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Services\ChannelIngest\ChannelIngestPayloadHasher;
use App\Services\ChannelIngest\ChannelIngestPayloadValidator;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use Tests\TestCase;

class ChannelIngestPayloadHasherTest extends TestCase
{
    private ChannelIngestPayloadHasher $hasher;

    private ChannelIngestPayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new ChannelIngestPayloadHasher;
        $this->validator = new ChannelIngestPayloadValidator;
    }

    public function test_hardware_metadata_canonicalization_is_deterministic_and_ignores_volatile_keys(): void
    {
        $left = $this->hasher->canonicalHardwareMetadata([
            'ordertype' => 'hardware',
            'retry_count' => 4,
            'paid_at' => '2026-09-07T12:00:00+05:30',
            'cashfree_payment_id' => 'cf_retry_1',
            'secret' => 'nope',
            'radiumbox_order_id' => '  BOX-9  ',
        ]);
        $right = $this->hasher->canonicalHardwareMetadata([
            'radiumbox_order_id' => 'BOX-9',
            'ordertype' => 'hardware',
            'attempt' => 9,
            'enqueued_at' => 'later',
        ]);

        $this->assertSame([
            'ordertype' => 'hardware',
            'radiumbox_order_id' => 'BOX-9',
        ], $left);
        $this->assertSame($left, $right);
    }

    public function test_hardware_hash_ignores_transport_timestamps_and_support_order_id(): void
    {
        $first = $this->hardwareRequest([
            'paid_at' => '2026-09-07T10:00:00+05:30',
            'ordered_at' => '2026-09-07T09:00:00+05:30',
            'support_order_id' => 11,
            'metadata' => [
                'retry_count' => 1,
                'ordertype' => 'hardware',
            ],
        ]);
        $second = $this->hardwareRequest([
            'paid_at' => '2026-09-07T11:59:00+05:30',
            'ordered_at' => '2026-09-07T11:58:00+05:30',
            'support_order_id' => 99,
            'metadata' => [
                'retry_count' => 8,
                'cashfree_payment_id' => 'changed',
                'ordertype' => 'hardware',
            ],
        ]);

        $this->assertSame($this->hasher->hash($first), $this->hasher->hash($second));
        $this->assertArrayNotHasKey('paid_at', $this->hasher->hardwareCanonical($first));
        $this->assertArrayNotHasKey('ordered_at', $this->hasher->hardwareCanonical($first));
        $this->assertArrayNotHasKey('support_order_id', $this->hasher->hardwareCanonical($first));
    }

    public function test_hardware_hash_changes_when_business_fields_change(): void
    {
        $base = $this->hardwareRequest();
        $gst = $this->hardwareRequest();
        $gstLines = $gst->lines;
        $changed = $this->validator->validate(array_merge($this->hardwarePayload(), [
            'lines' => [array_merge($this->hardwarePayload()['lines'][0], ['gst_percentage' => 12])],
        ]), StatutoryInvoiceChannel::RadiumBoxCom);

        $this->assertNotSame($this->hasher->hash($base), $this->hasher->hash($changed));
        $this->assertNotEmpty($gstLines);
    }

    public function test_service_hash_matches_the_historical_contract(): void
    {
        $request = $this->validator->validate($this->servicePayload(), StatutoryInvoiceChannel::RdServiceIn);

        $expected = hash('sha256', (string) json_encode($this->hasher->serviceCanonical($request)));

        $this->assertSame($expected, $this->hasher->hash($request));
        $this->assertArrayNotHasKey('metadata', $this->hasher->serviceCanonical($request));
        $this->assertArrayNotHasKey('model_id', $this->hasher->serviceCanonical($request)['lines'][0]);
        $this->assertArrayNotHasKey('tenders', $this->hasher->serviceCanonical($request));
    }

    public function test_hardware_hash_includes_split_tenders_and_omits_absent_tenders(): void
    {
        $plain = $this->hardwareRequest();
        $split = $this->hardwareRequest([
            'tenders' => [
                ['type' => 'wallet', 'amount' => 499],
                ['type' => 'cashfree', 'amount' => 2550],
            ],
        ]);

        $this->assertArrayNotHasKey('tenders', $this->hasher->hardwareCanonical($plain));
        $this->assertSame(
            $this->hasher->hash($plain),
            $this->hasher->hash($this->hardwareRequest(['tenders' => []])),
        );
        $this->assertNotSame($this->hasher->hash($plain), $this->hasher->hash($split));
        $this->assertSame(
            [
                ['type' => 'cashfree', 'amount' => 2550.0, 'reference' => null],
                ['type' => 'wallet', 'amount' => 499.0, 'reference' => null],
            ],
            $this->hasher->hardwareCanonical($split)['tenders'],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function hardwareRequest(array $overrides = []): ChannelOrderIngestRequest
    {
        return $this->validator->validate(
            array_merge($this->hardwarePayload(), $overrides),
            StatutoryInvoiceChannel::RadiumBoxCom,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hardwarePayload(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RDE900001',
            'source_order_id' => 'RDE900001',
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_rde900001',
            'currency' => 'INR',
            'customer' => ['name' => 'Buyer', 'phone' => '9000000001'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Madhya Pradesh',
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2584,
                'tax_total' => 465,
                'line_total' => 3049,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RD-1001',
            'source_order_id' => 'RD-1001',
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_RD-1001',
            'currency' => 'INR',
            'customer' => ['name' => 'Walk-in', 'phone' => '9000000001'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'RD Service',
                'sku' => 'RD-SVC',
                'qty' => 1,
                'unit_price' => 100,
                'hsn_sac' => '998313',
                'gst_percentage' => 18,
                'taxable_value' => 100,
                'tax_total' => 18,
                'line_total' => 118,
            ]],
        ];
    }
}
