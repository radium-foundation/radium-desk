<?php

namespace Tests\Feature\Shipping;

use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;
use App\Services\Shipping\HttpShiprocketGateway;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpShiprocketGatewayTest extends TestCase
{
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
        ]);
    }

    public function test_create_order_uses_adhoc_payload_and_login(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'order_id' => 88,
                'shipment_id' => 99,
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->createOrder($this->request());

        $this->assertSame('created', $result->status);
        $this->assertSame('88', $result->externalOrderId);
        $this->assertSame('99', $result->externalShipmentId);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/auth/login')
                && $request['email'] === 'ship@example.test';
        });
        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/orders/create/adhoc')
                && $request['order_id'] === 'HW-RDE900800'
                && $request['pickup_location'] === 'RADDELHI';
        });
    }

    public function test_search_finds_existing_merchant_order(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders*' => Http::response([
                'data' => [[
                    'id' => 88,
                    'channel_order_id' => 'HW-RDE900800',
                    'shipments' => [['id' => 99, 'awb' => 'AWB1']],
                ]],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->searchOrders('HW-RDE900800');

        $this->assertTrue($result->hasBindableIds());
        $this->assertSame('88', $result->externalOrderId);
        $this->assertSame('99', $result->externalShipmentId);
        $this->assertSame('AWB1', $result->awb);
    }

    public function test_timeout_is_retryable_and_does_not_invent_ids(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $result = (new HttpShiprocketGateway)->createOrder($this->request());

        $this->assertTrue($result->retryable);
        $this->assertNull($result->externalOrderId);
        $this->assertNull($result->externalShipmentId);
    }

    public function test_search_timeout_is_retryable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $result = (new HttpShiprocketGateway)->searchOrders('HW-RDE900800');

        $this->assertTrue($result->retryable);
        $this->assertFalse($result->found);
    }

    public function test_provider_5xx_is_retryable(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response(['message' => 'unavailable'], 503),
        ]);

        $result = (new HttpShiprocketGateway)->createOrder($this->request());

        $this->assertTrue($result->retryable);
        $this->assertSame('failed', $result->status);
    }

    public function test_list_courier_options_uses_serviceability_and_surfaces_provider_recommendation(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/serviceability*' => Http::response([
                'data' => [
                    'recommended_courier_company_id' => 44,
                    'available_courier_companies' => [
                        [
                            'courier_company_id' => 12,
                            'courier_name' => 'Surface',
                            'freight_charge' => 80,
                            'coverage_charges' => 0,
                            'etd' => '4 days',
                            'cod' => 0,
                            'prepaid' => 1,
                        ],
                        [
                            'courier_company_id' => 44,
                            'courier_name' => 'Express',
                            'freight_charge' => 120,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->listCourierOptions(new ShiprocketCourierOptionsRequest(
            pickupPostcode: '110001',
            deliveryPostcode: '452001',
            weight: 0.24,
            cod: 0,
        ));

        $this->assertSame('listed', $result->status);
        $this->assertTrue($result->recommendationReturned);
        $this->assertSame('44', $result->recommendedCourierId);
        $this->assertCount(2, $result->options);
        $this->assertSame('12', $result->options[0]->courierId);
        $this->assertSame('80', $result->options[0]->rate);
        $this->assertTrue($result->options[1]->providerRecommended);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/courier/serviceability/')
                && str_contains($request->url(), 'pickup_postcode=110001')
                && str_contains($request->url(), 'delivery_postcode=452001')
                && ! str_contains($request->url(), 'order_id=');
        });
    }

    public function test_list_courier_options_without_recommendation_is_not_invented(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/serviceability*' => Http::response([
                'data' => [
                    'available_courier_companies' => [
                        ['courier_company_id' => 12, 'courier_name' => 'Surface', 'freight_charge' => 80],
                    ],
                ],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->listCourierOptions(new ShiprocketCourierOptionsRequest(
            pickupPostcode: '110001',
            deliveryPostcode: '452001',
            weight: 0.24,
        ));

        $this->assertFalse($result->recommendationReturned);
        $this->assertNull($result->recommendedCourierId);
        $this->assertFalse($result->options[0]->providerRecommended);
    }

    public function test_assign_awb_reads_provider_evidence(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
                'response' => [
                    'data' => [
                        'awb_code' => 'AWB-99',
                        'courier_company_id' => 12,
                        'courier_name' => 'Delhivery',
                    ],
                ],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->assignAwb('99');

        $this->assertSame('assigned', $result->status);
        $this->assertSame('AWB-99', $result->awb);
        $this->assertSame('12', $result->courierId);
    }

    public function test_authentication_failure_does_not_create_an_order(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        try {
            (new HttpShiprocketGateway)->createOrder($this->request());
            $this->fail('Authentication failure must fail closed.');
        } catch (ShiprocketNonRetryableException $exception) {
            $this->assertSame('Shiprocket authentication failed.', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/auth/login'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/orders/create/adhoc'));
    }

    public function test_missing_credentials_do_not_call_http(): void
    {
        config([
            'shipping.api_email' => '',
            'shipping.api_password' => '',
        ]);
        Http::fake();

        try {
            (new HttpShiprocketGateway)->createOrder($this->request());
            $this->fail('Missing credentials must fail closed.');
        } catch (ShiprocketDisabledException) {
            Http::assertNothingSent();
        }
    }

    private function request(): ShiprocketCreateOrderRequest
    {
        return new ShiprocketCreateOrderRequest(
            merchantOrderId: 'HW-RDE900800',
            localShipmentId: 1,
            correlationId: 'corr-1',
            items: [[
                'name' => 'MSO1300',
                'sku' => 'RBIMSOE3L1',
                'units' => 1,
                'selling_price' => 3049,
            ]],
            orderDate: '2026-09-06 10:00',
            pickupLocation: 'RADDELHI',
            billingCustomerName: 'Hardware Buyer',
            billingAddress: '12 Shipping Street',
            billingCity: 'Indore',
            billingPincode: '452001',
            billingState: 'Madhya Pradesh',
            billingCountry: 'India',
            billingEmail: 'buyer@example.test',
            billingPhone: '9000000099',
            paymentMethod: 'Prepaid',
            subTotal: '3049.00',
            length: 20,
            breadth: 15,
            height: 10,
            weight: 0.4,
        );
    }
}
