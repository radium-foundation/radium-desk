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

    public function test_rin_hardware_uses_the_hardware_hash_not_the_service_hash(): void
    {
        $payload = [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RIN900777',
            'source_order_id' => 'RIN900777',
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'RIN900777',
            'currency' => 'INR',
            'customer' => ['name' => 'Buyer', 'phone' => '9000000001'],
            'place_of_supply_state' => 'West Bengal',
            'shipping_address' => [
                'line1' => '12 Street',
                'city' => 'Jaunpur',
                'state' => 'Uttar Pradesh',
                'pincode' => '222165',
            ],
            'ordered_at' => '2026-09-06T20:47:08+05:30',
            'metadata' => [
                'source' => 'rdservice.in',
                'source_order_type' => 'hardware_direct_buy',
                'source_product_id' => 'mantra-fingerprint',
                'retry_count' => 1,
            ],
            'lines' => [[
                'description' => 'MFS110',
                'sku' => 'RBMFS110L1',
                'catalog_sku' => 'mantra-fingerprint',
                'qty' => 1,
                'unit_price' => 2649,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2244.92,
                'tax_total' => 404.08,
                'line_total' => 2649,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 91001,
            ]],
        ];
        $first = $this->validator->validate($payload, StatutoryInvoiceChannel::RdServiceIn);
        $payload['metadata']['retry_count'] = 9;
        $payload['paid_at'] = '2026-09-07T12:00:00+05:30';
        $second = $this->validator->validate($payload, StatutoryInvoiceChannel::RdServiceIn);

        $this->assertSame($this->hasher->hash($first), $this->hasher->hash($second));
        $this->assertSame($this->hasher->hash($first), hash('sha256', (string) json_encode($this->hasher->hardwareCanonical($first))));
        $this->assertNotSame($this->hasher->hash($first), hash('sha256', (string) json_encode($this->hasher->serviceCanonical($first))));
        $this->assertSame('hardware_direct_buy', $this->hasher->hardwareCanonical($first)['metadata']['source_order_type'] ?? null);
    }

    public function test_matches_stored_accepts_current_hardware_hash(): void
    {
        $request = $this->validator->validate($this->rdpHardwarePayload(), StatutoryInvoiceChannel::RdServiceIn);

        $this->assertTrue($this->hasher->matchesStored($this->hasher->hash($request), $request));
    }

    public function test_matches_stored_rejects_unrelated_payload_changes(): void
    {
        $request = $this->validator->validate($this->rdpHardwarePayload(), StatutoryInvoiceChannel::RdServiceIn);
        $tampered = $this->validator->validate(array_merge($this->rdpHardwarePayload(), [
            'payment_reference' => 'RDP29-tampered',
        ]), StatutoryInvoiceChannel::RdServiceIn);

        $this->assertFalse($this->hasher->matchesStored($this->hasher->hash($request), $tampered));
    }

    public function test_legacy_rdp_service_hash_matches_pre_v4_0_95_stored_hash_on_replay(): void
    {
        $request = $this->validator->validate($this->rdpHardwarePayload(), StatutoryInvoiceChannel::RdServiceIn);
        $legacyStored = $this->hasher->legacyPreHardwareRdpServiceHash($request);

        $this->assertNotSame($legacyStored, $this->hasher->hash($request));
        $this->assertTrue($this->hasher->matchesStored($legacyStored, $request));
        $this->assertSame(
            '289c41b273c826921c33811708cfaef8fd433424a6ba086bc9952bdbea69ef9f',
            $legacyStored,
        );
    }

    public function test_current_rdp_hardware_hash_matches_new_rdp_ingests(): void
    {
        $request = $this->validator->validate($this->rdpHardwarePayload('RDP900101'), StatutoryInvoiceChannel::RdServiceIn);

        $this->assertSame(
            hash('sha256', (string) json_encode($this->hasher->hardwareCanonical($request))),
            $this->hasher->hash($request),
        );
        $this->assertTrue($this->hasher->matchesStored($this->hasher->hash($request), $request));
    }

    public function test_rin_hardware_cannot_use_legacy_rdp_service_hash_fallback(): void
    {
        $payload = [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RIN900778',
            'source_order_id' => 'RIN900778',
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'RIN900778',
            'currency' => 'INR',
            'customer' => ['name' => 'Buyer', 'phone' => '9000000001'],
            'place_of_supply_state' => 'West Bengal',
            'shipping_address' => [
                'line1' => '12 Street',
                'city' => 'Jaunpur',
                'state' => 'Uttar Pradesh',
                'pincode' => '222165',
            ],
            'ordered_at' => '2026-09-06T20:47:08+05:30',
            'metadata' => [
                'source' => 'rdservice.in',
                'source_order_type' => 'hardware_direct_buy',
                'source_product_id' => 'mantra-fingerprint',
            ],
            'lines' => [[
                'description' => 'MFS110',
                'sku' => 'RBMFS110L1',
                'catalog_sku' => 'mantra-fingerprint',
                'qty' => 1,
                'unit_price' => 2649,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2244.92,
                'tax_total' => 404.08,
                'line_total' => 2649,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 91001,
            ]],
        ];
        $request = $this->validator->validate($payload, StatutoryInvoiceChannel::RdServiceIn);
        $legacyServiceHash = $this->hasher->legacyPreHardwareRdpServiceHash($request);

        $this->assertNotSame($legacyServiceHash, $this->hasher->hash($request));
        $this->assertFalse($this->hasher->matchesStored($legacyServiceHash, $request));
    }

    public function test_legacy_rdp_tampered_payload_still_rejects(): void
    {
        $request = $this->validator->validate($this->rdpHardwarePayload(), StatutoryInvoiceChannel::RdServiceIn);
        $legacyStored = $this->hasher->legacyPreHardwareRdpServiceHash($request);
        $tampered = $this->validator->validate(array_merge($this->rdpHardwarePayload(), [
            'lines' => [array_merge($this->rdpHardwarePayload()['lines'][0], ['unit_price' => 3648])],
        ]), StatutoryInvoiceChannel::RdServiceIn);

        $this->assertFalse($this->hasher->matchesStored($legacyStored, $tampered));
    }

    public function test_service_order_still_uses_service_hash_and_rejects_tampering(): void
    {
        $request = $this->validator->validate($this->servicePayload(), StatutoryInvoiceChannel::RdServiceIn);
        $tampered = $this->validator->validate(array_merge($this->servicePayload(), [
            'payment_reference' => 'pay_RD-1001-changed',
        ]), StatutoryInvoiceChannel::RdServiceIn);

        $this->assertTrue($this->hasher->matchesStored($this->hasher->hash($request), $request));
        $this->assertFalse($this->hasher->matchesStored($this->hasher->hash($request), $tampered));
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
    private function rdpHardwarePayload(string $sourceId = 'RDP29'): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => $sourceId,
            'payment_method' => 'cashfree',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Pradyut Dey',
                'phone' => '9679441406',
                'email' => 'pradyutd104@gmail.com',
            ],
            'billing_address' => [
                'line1' => 'Pipursai, Pipursai',
                'city' => 'Kharagpur',
                'state' => 'West Bengal',
                'pincode' => '721445',
            ],
            'shipping_address' => [
                'line1' => 'Pipursai, Pipursai',
                'city' => 'Kharagpur',
                'state' => 'West Bengal',
                'pincode' => '721445',
            ],
            'place_of_supply_state' => 'West Bengal',
            'ordered_at' => '2026-09-18 18:17:48',
            'metadata' => [
                'source' => 'rdservice.in',
                'source_order_type' => 'hardware_direct_buy',
                'source_product_id' => 'mantra-fingerprint',
                'source_state' => 'West Bengal',
                'source_district' => 'Kharagpur',
                'product_summary' => 'MFS110 L1 (STQC) Fingerprint Scanner × 1, RD 3Y, Warranty 3Y, USB+Type-C',
                'bundle_rd_years' => 3,
                'bundle_warranty_years' => 3,
                'bundle_otg' => 'USB+Type-C',
            ],
            'lines' => [[
                'description' => 'MFS110 L1 (STQC) Fingerprint Scanner',
                'sku' => 'RBMFS110L1',
                'catalog_sku' => 'mantra-fingerprint',
                'qty' => 1,
                'unit_price' => 3647,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 3090.68,
                'tax_total' => 556.32,
                'line_total' => 3647,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 91001,
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
