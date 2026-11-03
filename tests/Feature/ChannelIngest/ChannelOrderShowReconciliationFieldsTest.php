<?php

namespace Tests\Feature\ChannelIngest;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelIngestAttempt;
use App\Models\CommerceOrder;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelOrderShowReconciliationFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const RADIUMBOX_SECRET = 'test-radiumbox-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.radiumbox_com' => self::RADIUMBOX_SECRET,
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.auto_issue_invoice' => false,
        ]);
    }

    public function test_authenticated_get_returns_persisted_reconciliation_fields(): void
    {
        $payload = $this->radiumboxPayload('RB1258', '6882034738', '497.00');
        $this->signedPost($payload)->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RB1258')->firstOrFail();
        $attemptsBefore = ChannelIngestAttempt::query()->count();

        $response = $this->signedGet(
            StatutoryInvoiceChannel::RadiumBoxCom->value,
            'commerce_order',
            'RB1258',
            self::RADIUMBOX_SECRET,
        );

        $response->assertOk()
            ->assertJsonPath('order_no', $order->order_no)
            ->assertJsonPath('channel', StatutoryInvoiceChannel::RadiumBoxCom->value)
            ->assertJsonPath('source_type', 'commerce_order')
            ->assertJsonPath('source_id', 'RB1258')
            ->assertJsonPath('payment_reference', '6882034738')
            ->assertJsonPath('idempotency_key', 'statutory:radiumbox_com:commerce_order:RB1258')
            ->assertJsonPath('order_value', '497.00')
            ->assertJsonPath('currency', 'INR');

        $this->assertSame($attemptsBefore, ChannelIngestAttempt::query()->count());
    }

    public function test_nullable_payment_reference_and_order_value_are_not_fabricated(): void
    {
        $this->signedPost($this->radiumboxPayload('RB-NULL-REF'))->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RB-NULL-REF')->firstOrFail();
        $order->forceFill([
            'payment_reference' => null,
            'order_value' => null,
        ])->save();

        $this->signedGet(
            StatutoryInvoiceChannel::RadiumBoxCom->value,
            'commerce_order',
            'RB-NULL-REF',
            self::RADIUMBOX_SECRET,
        )
            ->assertOk()
            ->assertJsonPath('payment_reference', null)
            ->assertJsonPath('idempotency_key', 'statutory:radiumbox_com:commerce_order:RB-NULL-REF')
            ->assertJsonPath('order_value', null)
            ->assertJsonPath('currency', 'INR');
    }

    public function test_get_requires_hmac_authentication(): void
    {
        $this->signedPost($this->radiumboxPayload('RB-AUTH'))->assertCreated();

        $this->getJson('/api/v1/channel-orders/commerce_order/RB-AUTH', [
            'X-Desk-Channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
        ])->assertUnauthorized();
    }

    public function test_get_enforces_channel_scoping(): void
    {
        $this->signedPost($this->radiumboxPayload('RB-SCOPE'))->assertCreated();

        $timestamp = (string) time();
        $this->call('GET', '/api/v1/channel-orders/commerce_order/RB-SCOPE', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceIn->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, '', 'test-rdservice-in-secret'),
        ])->assertNotFound();
    }

    public function test_missing_order_returns_existing_not_found_response(): void
    {
        $timestamp = (string) time();

        $this->call('GET', '/api/v1/channel-orders/commerce_order/MISSING', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, '', self::RADIUMBOX_SECRET),
        ])
            ->assertNotFound()
            ->assertJsonPath('error', 'Channel order was not found.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::RADIUMBOX_SECRET),
        ], $body);
    }

    private function signedGet(string $channel, string $sourceType, string $sourceId, string $secret)
    {
        $timestamp = (string) time();

        return $this->call('GET', '/api/v1/channel-orders/'.$sourceType.'/'.$sourceId, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $channel,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, '', $secret),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function radiumboxPayload(
        string $sourceId,
        ?string $paymentReference = null,
        ?string $lineTotal = '118.00',
    ): array {
        $paymentReference ??= 'pay_'.$sourceId;

        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => $paymentReference,
            'payment_method' => 'cashfree',
            'currency' => 'INR',
            'order_value' => $lineTotal !== null ? (float) $lineTotal : null,
            'customer' => [
                'name' => 'Buyer',
                'phone' => '9000000001',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'place_of_supply_state' => 'Delhi',
            'lines' => [
                [
                    'description' => 'RD Service',
                    'sku' => '1089',
                    'qty' => 1,
                    'unit_price' => $lineTotal !== null ? (float) $lineTotal : 0,
                    'hsn_sac' => '998313',
                    'gst_percentage' => 18,
                    'taxable_value' => $lineTotal !== null ? (float) $lineTotal : 0,
                    'tax_total' => 0,
                    'line_total' => $lineTotal !== null ? (float) $lineTotal : 0,
                    'shipping_line_kind' => 'rd_service',
                    'requires_shipping' => false,
                ],
            ],
        ];
    }
}
