<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\Data\ShiprocketAdhocAddressNormalizer;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiprocketCreateOrderRequestTest extends TestCase
{
    private const RDE318516_LINE1 = 'Dr. Chandramma Dayananda Sagar Institute of Medical Education & Research, Deverakaggalahalli, Kanakapura Road,Bengaluru South District, Karnataka - 562 112';

    private const RDE318516_LINE2 = 'BANGALORE KANAKAPURA HIGHWAY, NEAR HAROHALLI';

    public function test_adhoc_payload_keeps_city_at_or_under_official_max(): void
    {
        $short = $this->request(billingCity: 'Kharagpur');
        $this->assertSame('Kharagpur', $short->toAdhocPayload()['billing_city']);

        $rdeCity = $this->request(billingCity: 'Paschim Medinipur (West Midnapore)');
        $this->assertSame(34, mb_strlen('Paschim Medinipur (West Midnapore)', 'UTF-8'));
        $this->assertSame('Paschim Medinipur', $rdeCity->toAdhocPayload()['billing_city']);
        $this->assertSame('Paschim Medinipur (West Midnapore)', $rdeCity->billingCity);
        $this->assertLessThanOrEqual(30, mb_strlen($rdeCity->toAdhocPayload()['billing_city'], 'UTF-8'));

        $long = $this->request(billingCity: 'A very long city name without alias');
        $this->assertSame('A very long city name without', $long->toAdhocPayload()['billing_city']);
        $this->assertLessThanOrEqual(30, mb_strlen($long->toAdhocPayload()['billing_city'], 'UTF-8'));
    }

    public function test_adhoc_payload_still_omits_empty_channel_id(): void
    {
        $payload = $this->request(billingCity: 'Indore')->toAdhocPayload();

        $this->assertArrayNotHasKey('channel_id', $payload);
        $this->assertSame('', $payload['billing_last_name']);
        $this->assertSame('Prepaid', $payload['payment_method']);
        $this->assertArrayNotHasKey('courier_id', $payload);
    }

    public function test_rde318516_payload_is_normalized_without_mutating_request(): void
    {
        $request = $this->request(
            billingCity: 'Ramanagara',
            billingAddress: self::RDE318516_LINE1,
            billingAddress2: self::RDE318516_LINE2,
            billingState: 'Karnataka',
            billingPincode: '562112',
        );

        $payload = $request->toAdhocPayload();

        $this->assertSame(self::RDE318516_LINE1, $request->billingAddress);
        $this->assertSame(self::RDE318516_LINE2, $request->billingAddress2);
        $this->assertSame('Karnataka', $request->billingState);
        $this->assertSame('562112', $request->billingPincode);
        $this->assertSame('India', $request->billingCountry);
        $this->assertSame('Karnataka', $payload['billing_state']);
        $this->assertSame('562112', $payload['billing_pincode']);
        $this->assertSame('India', $payload['billing_country']);
        $this->assertSame('Ramanagara', $payload['billing_city']);
        $this->assertNotSame(self::RDE318516_LINE1, $payload['billing_address']);
        $this->assertSame(self::RDE318516_LINE2, $payload['billing_address_2']);
        $this->assertLessThanOrEqual(
            190,
            ShiprocketAdhocAddressNormalizer::combinedCharacterLength(
                $payload['billing_address'],
                $payload['billing_address_2'],
            ),
        );
        $this->assertSame(178, ShiprocketAdhocAddressNormalizer::combinedCharacterLength(
            $payload['billing_address'],
            $payload['billing_address_2'],
        ));
        $this->assertStringContainsString('Bengaluru South District', $payload['billing_address']);
        $this->assertStringContainsString('NEAR HAROHALLI', $payload['billing_address_2']);
        $this->assertStringNotContainsString('Karnataka - 562 112', $payload['billing_address']);
    }

    public function test_over_limit_without_safe_suffix_fails_closed_before_payload(): void
    {
        $request = $this->request(
            billingCity: 'Ramanagara',
            billingAddress: str_repeat('A', 150),
            billingAddress2: str_repeat('B', 50),
            billingState: 'Karnataka',
            billingPincode: '562112',
        );

        try {
            $request->toAdhocPayload();
            $this->fail('Addresses over 190 without a safe suffix must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame(str_repeat('A', 150), $request->billingAddress);
            $this->assertSame(str_repeat('B', 50), $request->billingAddress2);
            $this->assertArrayHasKey('shipping', $exception->errors());
            $this->assertStringContainsString('190 characters', $exception->errors()['shipping'][0]);
        }
    }

    public function test_shipping_is_billing_false_normalizes_each_pair_separately(): void
    {
        $billingLine1 = str_repeat('B', 160).', Maharashtra - 400 001';
        $billingLine2 = 'BILLING LANDMARK';
        $shippingLine1 = self::RDE318516_LINE1;
        $shippingLine2 = self::RDE318516_LINE2;

        $request = new ShiprocketCreateOrderRequest(
            merchantOrderId: 'HW-RDE900800',
            localShipmentId: 1,
            correlationId: 'corr-1',
            items: [[
                'name' => 'MSO1300',
                'sku' => '946',
                'units' => 1,
                'selling_price' => 3049,
            ]],
            orderDate: '2026-09-06 10:00',
            pickupLocation: 'RADDELHI',
            billingCustomerName: 'Billing Buyer',
            billingAddress: $billingLine1,
            billingCity: 'Mumbai',
            billingPincode: '400001',
            billingState: 'Maharashtra',
            billingCountry: 'India',
            billingEmail: 'buyer@example.test',
            billingPhone: '9000000099',
            shippingIsBilling: false,
            paymentMethod: 'Prepaid',
            subTotal: '3049.00',
            length: 20,
            breadth: 15,
            height: 10,
            weight: 0.4,
            billingAddress2: $billingLine2,
            shippingCustomerName: 'Shipping Buyer',
            shippingAddress: $shippingLine1,
            shippingAddress2: $shippingLine2,
            shippingCity: 'Ramanagara',
            shippingPincode: '562112',
            shippingState: 'Karnataka',
            shippingCountry: 'India',
            shippingPhone: '9000000088',
        );

        $payload = $request->toAdhocPayload();

        $this->assertSame($billingLine1, $request->billingAddress);
        $this->assertSame($shippingLine1, $request->shippingAddress);
        $this->assertFalse($payload['shipping_is_billing']);
        $this->assertSame('Maharashtra', $payload['billing_state']);
        $this->assertSame('400001', $payload['billing_pincode']);
        $this->assertSame('Karnataka', $payload['shipping_state']);
        $this->assertSame('562112', $payload['shipping_pincode']);
        $this->assertSame(str_repeat('B', 160), $payload['billing_address']);
        $this->assertSame($billingLine2, $payload['billing_address_2']);
        $this->assertNotSame($shippingLine1, $payload['shipping_address']);
        $this->assertSame($shippingLine2, $payload['shipping_address_2']);
        $this->assertStringContainsString('Bengaluru South District', $payload['shipping_address']);
        $this->assertStringNotContainsString('Karnataka - 562 112', $payload['shipping_address']);
        $this->assertStringNotContainsString('Maharashtra', $payload['shipping_address']);
        $this->assertStringNotContainsString('Bengaluru', $payload['billing_address']);
        $this->assertLessThanOrEqual(
            190,
            ShiprocketAdhocAddressNormalizer::combinedCharacterLength(
                $payload['billing_address'],
                $payload['billing_address_2'],
            ),
        );
        $this->assertLessThanOrEqual(
            190,
            ShiprocketAdhocAddressNormalizer::combinedCharacterLength(
                $payload['shipping_address'],
                $payload['shipping_address_2'],
            ),
        );
    }

    private function request(
        string $billingCity,
        string $billingAddress = '12 Shipping Street',
        ?string $billingAddress2 = null,
        string $billingState = 'Madhya Pradesh',
        string $billingPincode = '452001',
    ): ShiprocketCreateOrderRequest {
        return new ShiprocketCreateOrderRequest(
            merchantOrderId: 'HW-RDE900800',
            localShipmentId: 1,
            correlationId: 'corr-1',
            items: [[
                'name' => 'MSO1300',
                'sku' => '946',
                'units' => 1,
                'selling_price' => 3049,
            ]],
            orderDate: '2026-09-06 10:00',
            pickupLocation: 'RADDELHI',
            billingCustomerName: 'Hardware Buyer',
            billingAddress: $billingAddress,
            billingCity: $billingCity,
            billingPincode: $billingPincode,
            billingState: $billingState,
            billingCountry: 'India',
            billingEmail: 'buyer@example.test',
            billingPhone: '9000000099',
            paymentMethod: 'Prepaid',
            subTotal: '3049.00',
            length: 20,
            breadth: 15,
            height: 10,
            weight: 0.4,
            billingAddress2: $billingAddress2,
        );
    }
}
