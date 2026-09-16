<?php

namespace Tests\Feature\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;
use App\Services\Shipping\HttpShiprocketGateway;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
            'shipping.token_ttl_seconds' => 86400,
            'shipping.token_expiry_margin_seconds' => 120,
            'shipping.auth_connect_retries' => 1,
        ]);

        Cache::flush();
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
                && $request['pickup_location'] === 'RADDELHI'
                && $request['billing_last_name'] === ''
                && $request['billing_city'] === 'Indore';
        });
    }

    public function test_create_order_clamps_billing_city_to_official_max(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'order_id' => 88,
                'shipment_id' => 99,
            ], 200),
        ]);

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
            billingCustomerName: 'Hardware Buyer',
            billingAddress: '12 Shipping Street',
            billingCity: 'Paschim Medinipur (West Midnapore)',
            billingPincode: '721130',
            billingState: 'West Bengal',
            billingCountry: 'India',
            billingEmail: 'buyer@example.test',
            billingPhone: '9000000099',
            paymentMethod: 'Prepaid',
            subTotal: '2549.00',
            length: 14,
            breadth: 9,
            height: 7,
            weight: 0.24,
        );

        (new HttpShiprocketGateway)->createOrder($request);

        Http::assertSent(function ($httpRequest): bool {
            return str_ends_with($httpRequest->url(), '/orders/create/adhoc')
                && $httpRequest['billing_city'] === 'Paschim Medinipur'
                && mb_strlen((string) $httpRequest['billing_city'], 'UTF-8') <= 30
                && ! array_key_exists('channel_id', $httpRequest->data())
                && ! array_key_exists('courier_id', $httpRequest->data());
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

    public function test_create_validation_error_includes_safe_field_errors(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'message' => 'Oops! Invalid Data.',
                'status_code' => 422,
                'errors' => [
                    'billing_last_name' => ['validation.present'],
                    'password' => ['secret-must-not-appear'],
                ],
            ], 422),
        ]);

        try {
            (new HttpShiprocketGateway)->createOrder($this->request());
            $this->fail('Provider validation must fail closed.');
        } catch (ShiprocketNonRetryableException $exception) {
            $this->assertStringContainsString('Oops! Invalid Data.', $exception->getMessage());
            $this->assertStringContainsString('HTTP 422', $exception->getMessage());
            $this->assertStringContainsString('billing_last_name: validation.present', $exception->getMessage());
            $this->assertStringNotContainsString('secret-must-not-appear', $exception->getMessage());
            $this->assertStringNotContainsString('password', $exception->getMessage());
        }
    }

    public function test_create_http_200_without_ids_keeps_provider_errors(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'message' => 'Oops! Invalid Data.',
                'status_code' => 422,
                'errors' => [
                    'billing_city' => ['The billing city is invalid.'],
                ],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->createOrder($this->request());

        $this->assertSame('rejected', $result->status);
        $this->assertFalse($result->retryable);
        $this->assertNull($result->externalOrderId);
        $this->assertNull($result->externalShipmentId);
        $this->assertStringContainsString('Oops! Invalid Data.', (string) $result->error);
        $this->assertStringContainsString('billing_city: The billing city is invalid.', (string) $result->error);
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
                && str_contains($request->url(), 'cod=0')
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

    public function test_generate_label_posts_shipment_id_array(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/generate/label' => Http::response([
                'label_created' => 1,
                'label_url' => 'https://labels.test/99.pdf',
                'not_created' => [],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->generateLabel('99');

        $this->assertSame('generated', $result->status);
        $this->assertSame('https://labels.test/99.pdf', $result->url);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/courier/generate/label')
                && $request->method() === 'POST'
                && $request['shipment_id'] === [99];
        });
    }

    public function test_generate_manifest_posts_shipment_id_array(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/manifests/generate' => Http::response([
                'manifest_url' => 'https://manifests.test/99.pdf',
                'manifest_id' => 'MF-99',
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->generateManifest('99');

        $this->assertSame('generated', $result->status);
        $this->assertSame('https://manifests.test/99.pdf', $result->url);
        $this->assertSame('MF-99', $result->documentId);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/manifests/generate')
                && $request->method() === 'POST'
                && $request['shipment_id'] === [99];
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/manifests/print')
            || str_contains($request->url(), '/orders/print/manifest'));
    }

    public function test_generate_manifest_without_url_or_id_is_rejected(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/manifests/generate' => Http::response([
                'message' => 'Manifest not generated',
                'check_ids' => [99],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->generateManifest('99');

        $this->assertSame('rejected', $result->status);
        $this->assertNull($result->url);
        $this->assertNull($result->documentId);
        $this->assertSame('Manifest not generated', $result->error);
    }

    public function test_request_pickup_success_is_requested(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
                'pickup_status' => 1,
                'response' => [
                    'pickup_scheduled_date' => '2026-09-08 18:00:00',
                ],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->requestPickup('99');

        $this->assertSame('requested', $result->status);
        $this->assertFalse($result->alreadyQueued);
        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/courier/generate/pickup')
                && $request->method() === 'POST'
                && $request['shipment_id'] === ['99'];
        });
    }

    public function test_request_pickup_http_400_already_in_queue_is_reconciled(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
                'message' => 'Already in Pickup Queue',
                'status_code' => 400,
            ], 400),
        ]);

        $result = (new HttpShiprocketGateway)->requestPickup('99');

        $this->assertSame('already_requested', $result->status);
        $this->assertTrue($result->alreadyQueued);
        $this->assertTrue($result->isAccepted());
        $this->assertSame('HTTP 400 — Already in Pickup Queue', $result->error);
        Http::assertSentCount(2);
    }

    public function test_request_pickup_http_400_already_in_queue_with_punctuation_is_reconciled(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
                'message' => 'Already in Pickup Queue.',
            ], 400),
        ]);

        $result = (new HttpShiprocketGateway)->requestPickup('99');

        $this->assertSame('already_requested', $result->status);
        $this->assertTrue($result->alreadyQueued);
        $this->assertTrue($result->isAccepted());
        $this->assertSame('HTTP 400 — Already in Pickup Queue.', $result->error);
    }

    public function test_request_pickup_http_400_other_message_stays_rejected(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
                'message' => 'AWB not assigned',
            ], 400),
        ]);

        $result = (new HttpShiprocketGateway)->requestPickup('99');

        $this->assertSame('rejected', $result->status);
        $this->assertFalse($result->alreadyQueued);
        $this->assertFalse($result->isAccepted());
        $this->assertSame('HTTP 400 — AWB not assigned', $result->error);
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

    public function test_assign_awb_http_400_courier_not_serviceable_is_rejected_not_retryable(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
                'message' => 'Given courier not serviceable.',
            ], 400),
        ]);

        $result = (new HttpShiprocketGateway)->assignAwb('1585251560', '15084');

        $this->assertSame('rejected', $result->status);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('HTTP 400', (string) $result->error);
        $this->assertStringContainsString('Given courier not serviceable', (string) $result->error);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/courier/assign/awb')
            && $request['shipment_id'] === '1585251560'
            && $request['courier_id'] === '15084');
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

    public function test_create_order_sends_normalized_combined_address(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'order_id' => 88,
                'shipment_id' => 99,
            ], 200),
        ]);

        $line1 = 'Dr. Chandramma Dayananda Sagar Institute of Medical Education & Research, Deverakaggalahalli, Kanakapura Road,Bengaluru South District, Karnataka - 562 112';
        $line2 = 'BANGALORE KANAKAPURA HIGHWAY, NEAR HAROHALLI';

        $request = new ShiprocketCreateOrderRequest(
            merchantOrderId: 'HW-RDE318516',
            localShipmentId: 10,
            correlationId: 'corr-516',
            items: [[
                'name' => 'MSO1300',
                'sku' => '946',
                'units' => 10,
                'selling_price' => 2499,
            ]],
            orderDate: '2026-09-09 09:35',
            pickupLocation: 'RADDELHI',
            billingCustomerName: 'CDSIMER',
            billingAddress: $line1,
            billingCity: 'Ramanagara',
            billingPincode: '562112',
            billingState: 'Karnataka',
            billingCountry: 'India',
            billingEmail: 'buyer@example.test',
            billingPhone: '9000000099',
            paymentMethod: 'Prepaid',
            subTotal: '24990.00',
            length: 25,
            breadth: 22,
            height: 18,
            weight: 2.5,
            billingAddress2: $line2,
        );

        (new HttpShiprocketGateway)->createOrder($request);

        $this->assertSame($line1, $request->billingAddress);
        $this->assertSame($line2, $request->billingAddress2);

        Http::assertSent(function ($httpRequest) use ($line1, $line2): bool {
            if (! str_ends_with($httpRequest->url(), '/orders/create/adhoc')) {
                return false;
            }

            $address = (string) $httpRequest['billing_address'];
            $address2 = (string) $httpRequest['billing_address_2'];

            return $httpRequest['billing_state'] === 'Karnataka'
                && $httpRequest['billing_pincode'] === '562112'
                && $httpRequest['billing_country'] === 'India'
                && $address !== $line1
                && $address2 === $line2
                && str_contains($address, 'Bengaluru South District')
                && str_contains($address2, 'NEAR HAROHALLI')
                && ! str_contains($address, 'Karnataka - 562 112')
                && (mb_strlen($address, 'UTF-8') + mb_strlen($address2, 'UTF-8')) <= 190;
        });
    }

    public function test_create_order_does_not_call_http_when_address_cannot_be_normalized(): void
    {
        Http::fake();

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
            billingCustomerName: 'Hardware Buyer',
            billingAddress: str_repeat('A', 150),
            billingCity: 'Ramanagara',
            billingPincode: '562112',
            billingState: 'Karnataka',
            billingCountry: 'India',
            billingEmail: 'buyer@example.test',
            billingPhone: '9000000099',
            paymentMethod: 'Prepaid',
            subTotal: '3049.00',
            length: 20,
            breadth: 15,
            height: 10,
            weight: 0.4,
            billingAddress2: str_repeat('B', 50),
        );

        try {
            (new HttpShiprocketGateway)->createOrder($request);
            $this->fail('Over-limit addresses without a safe suffix must not call Shiprocket.');
        } catch (ValidationException $exception) {
            Http::assertNothingSent();
            $this->assertArrayHasKey('shipping', $exception->errors());
        }
    }

    public function test_first_auth_is_cached_with_returned_ttl(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response([
                'token' => 'tok-1',
                'expires_in' => 43200,
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->acquireToken();

        $this->assertSame('tok-1', $result->token);
        $this->assertSame(43200, $result->ttlSeconds);

        $cached = Cache::get($this->tokenCacheKey());
        $this->assertIsArray($cached);
        $this->assertSame('tok-1', $cached['token']);
        $this->assertSame(43200, $cached['ttl_seconds']);
        $this->assertSame(now()->getTimestamp(), $cached['cached_at']);
        $this->assertSame(1, $this->loginRequestCount());
    }

    public function test_second_request_reuses_cached_token_without_login(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'order_id' => 88,
                'shipment_id' => 99,
            ], 200),
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

        $created = (new HttpShiprocketGateway)->createOrder($this->request());
        $assigned = (new HttpShiprocketGateway)->assignAwb((string) $created->externalShipmentId, '12');

        $this->assertSame('created', $created->status);
        $this->assertSame('assigned', $assigned->status);
        $this->assertSame('AWB-99', $assigned->awb);
        $this->assertSame(1, $this->loginRequestCount());
    }

    public function test_near_expiry_cached_token_is_ignored_and_auth_runs_again(): void
    {
        Cache::put($this->tokenCacheKey(), [
            'token' => 'tok-stale',
            'ttl_seconds' => 86400,
            'cached_at' => now()->subSeconds(86400 - 30)->getTimestamp(),
        ], 3600);

        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-fresh'], 200),
        ]);

        $result = (new HttpShiprocketGateway)->acquireToken();

        $this->assertSame('tok-fresh', $result->token);
        $this->assertSame(1, $this->loginRequestCount());
        $this->assertSame('tok-fresh', Cache::get($this->tokenCacheKey())['token'] ?? null);
    }

    public function test_auth_failure_does_not_cache_a_token(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        try {
            (new HttpShiprocketGateway)->acquireToken();
            $this->fail('Authentication failure must fail closed.');
        } catch (ShiprocketNonRetryableException $exception) {
            $this->assertSame('Shiprocket authentication failed.', $exception->getMessage());
        }

        $this->assertNull(Cache::get($this->tokenCacheKey()));
        $this->assertSame(1, $this->loginRequestCount());
    }

    public function test_empty_login_token_is_not_cached(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => ''], 200),
        ]);

        try {
            (new HttpShiprocketGateway)->acquireToken();
            $this->fail('Empty token must fail closed.');
        } catch (ShiprocketNonRetryableException $exception) {
            $this->assertSame('Shiprocket authentication failed.', $exception->getMessage());
        }

        $this->assertNull(Cache::get($this->tokenCacheKey()));
    }

    public function test_downstream_401_invalidates_cached_token_and_next_request_logs_in(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::sequence()
                ->push(['token' => 'tok-1'], 200)
                ->push(['token' => 'tok-2'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::sequence()
                ->push(['message' => 'Unauthenticated'], 401)
                ->push([
                    'response' => [
                        'data' => [
                            'awb_code' => 'AWB-2',
                            'courier_company_id' => 12,
                            'courier_name' => 'Delhivery',
                        ],
                    ],
                ], 200),
        ]);

        $first = (new HttpShiprocketGateway)->assignAwb('99', '12');
        $this->assertSame('failed', $first->status);
        $this->assertTrue($first->retryable);
        $this->assertNull(Cache::get($this->tokenCacheKey()));

        $second = (new HttpShiprocketGateway)->assignAwb('99', '12');
        $this->assertSame('assigned', $second->status);
        $this->assertSame('AWB-2', $second->awb);
        $this->assertSame(2, $this->loginRequestCount());
        $this->assertSame('tok-2', Cache::get($this->tokenCacheKey())['token'] ?? null);
    }

    public function test_awb_http_400_does_not_invalidate_cache_or_retry_assignment(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
                'message' => 'Given courier not serviceable.',
            ], 400),
        ]);

        $result = (new HttpShiprocketGateway)->assignAwb('99', '15084');

        $this->assertSame('rejected', $result->status);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('HTTP 400', (string) $result->error);
        $this->assertStringContainsString('Given courier not serviceable.', (string) $result->error);
        $this->assertSame('tok-1', Cache::get($this->tokenCacheKey())['token'] ?? null);
        $this->assertSame(1, $this->loginRequestCount());
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn (array $pair): bool => str_ends_with($pair[0]->url(), '/courier/assign/awb')
        )->count());
    }

    public function test_two_gateways_authenticate_once_under_shared_cache(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-shared'], 200),
            'https://apiv2.shiprocket.in/v1/external/courier/serviceability*' => Http::response([
                'data' => [
                    'recommended_courier_company_id' => 12,
                    'available_courier_companies' => [
                        ['courier_company_id' => 12, 'courier_name' => 'Delhivery', 'freight_charge' => 80],
                    ],
                ],
            ], 200),
        ]);

        $request = new ShiprocketCourierOptionsRequest(
            pickupPostcode: '110019',
            deliveryPostcode: '452001',
            weight: 0.4,
            cod: 0,
        );
        $first = (new HttpShiprocketGateway)->listCourierOptions($request);
        $second = (new HttpShiprocketGateway)->listCourierOptions($request);

        $this->assertSame('listed', $first->status);
        $this->assertSame('listed', $second->status);
        $this->assertSame('12', $first->recommendedCourierId);
        $this->assertSame(1, $this->loginRequestCount());
    }

    public function test_null_cache_store_still_authenticates(): void
    {
        config(['cache.default' => 'null']);

        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
                'order_id' => 88,
                'shipment_id' => 99,
            ], 200),
        ]);

        $first = (new HttpShiprocketGateway)->createOrder($this->request());
        $second = (new HttpShiprocketGateway)->createOrder($this->request());

        $this->assertSame('created', $first->status);
        $this->assertSame('created', $second->status);
        $this->assertSame(2, $this->loginRequestCount());
    }

    public function test_auth_dns_timeout_retries_once_then_succeeds(): void
    {
        $logins = 0;
        Http::fake(function ($request) use (&$logins) {
            if (str_ends_with($request->url(), '/auth/login')) {
                $logins++;
                if ($logins === 1) {
                    throw new ConnectionException(
                        'cURL error 28: Resolving timed out after 5001 milliseconds for https://apiv2.shiprocket.in/v1/external/auth/login',
                    );
                }

                return Http::response(['token' => 'tok-recovered'], 200);
            }

            return Http::response(['order_id' => 88, 'shipment_id' => 99], 200);
        });

        $result = (new HttpShiprocketGateway)->createOrder($this->request());

        $this->assertSame('created', $result->status);
        $this->assertSame(2, $logins);
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn (array $pair): bool => str_ends_with($pair[0]->url(), '/orders/create/adhoc')
        )->count());
    }

    public function test_auth_dns_timeout_is_retryable_and_does_not_assign_awb(): void
    {
        $logins = 0;
        $awbs = 0;
        Http::fake(function ($request) use (&$logins, &$awbs) {
            if (str_ends_with($request->url(), '/auth/login')) {
                $logins++;
                throw new ConnectionException(
                    'cURL error 28: Resolving timed out after 5001 milliseconds for https://apiv2.shiprocket.in/v1/external/auth/login',
                );
            }
            if (str_contains($request->url(), '/courier/assign/awb')) {
                $awbs++;
            }

            return Http::response(['awb_code' => 'SHOULD-NOT-ASSIGN'], 200);
        });

        $result = (new HttpShiprocketGateway)->assignAwb('1586581189', '15084');

        $this->assertSame('failed', $result->status);
        $this->assertTrue($result->retryable);
        $this->assertNull($result->awb);
        $this->assertStringContainsString('timed out', strtolower((string) $result->error));
        $this->assertSame(2, $logins);
        $this->assertSame(0, $awbs);
    }

    public function test_null_gateway_still_disables_shipping(): void
    {
        $this->assertInstanceOf(NullShiprocketGateway::class, app(ShiprocketGateway::class));

        try {
            (new NullShiprocketGateway)->acquireToken();
            $this->fail('Null gateway must stay disabled.');
        } catch (ShiprocketDisabledException $exception) {
            $this->assertSame(NullShiprocketGateway::MESSAGE, $exception->getMessage());
        }
    }

    private function tokenCacheKey(): string
    {
        $email = trim((string) config('shipping.api_email'));
        $password = (string) config('shipping.api_password');

        return 'shipping:shiprocket:token:'.hash('sha256', $email."\0".$password);
    }

    private function loginRequestCount(): int
    {
        return collect(Http::recorded())->filter(
            fn (array $pair): bool => str_ends_with($pair[0]->url(), '/auth/login')
        )->count();
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
