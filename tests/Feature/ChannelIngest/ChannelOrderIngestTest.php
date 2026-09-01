<?php

namespace Tests\Feature\ChannelIngest;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelIngestAttempt;
use App\Models\CommerceOrder;
use App\Models\FinanceJournal;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\ChannelIngest\ChannelIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChannelOrderIngestTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-rdservice-in-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.rdservice_in' => self::SECRET,
            'channel_ingest.secrets.radiumbox_com' => 'test-radiumbox-secret',
            'channel_ingest.secrets.rdservice_net' => '',
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
        ]);
    }

    public function test_valid_channel_submission_is_accepted_without_minting(): void
    {
        $response = $this->signedPost($this->payload());

        $response->assertCreated()
            ->assertJsonPath('status', CommerceOrderStatus::InvoicePending->value)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('invoice', null)
            ->assertJsonPath('idempotency_key', 'statutory:rdservice_in:commerce_order:RD-1001');

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
        $this->assertSame('CO-000001', CommerceOrder::query()->value('order_no'));
        $this->assertTrue((bool) CommerceOrder::query()->value('invoice_eligible'));
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        $first = $this->signedPost($this->payload());
        $second = $this->signedPost($this->payload());

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('order_no'), $second->json('order_no'));
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_conflicting_payload_for_the_same_source_is_rejected(): void
    {
        $this->signedPost($this->payload())->assertCreated();

        $conflict = $this->payload();
        $conflict['lines'][0]['unit_price'] = 200;

        $this->signedPost($conflict)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        $this->assertSame(1, CommerceOrder::query()->count());
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['lines'] = [];

        $this->signedPost($payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'rejected');

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_missing_customer_identity_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['customer'] = [];

        $this->signedPost($payload)->assertStatus(422);
        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_unknown_channel_is_unauthorized(): void
    {
        $this->signedPost($this->payload(), channel: 'admin_erp')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'unauthorized');

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_desk_pos_cannot_use_the_http_ingest_api(): void
    {
        $payload = $this->payload();
        $payload['channel'] = StatutoryInvoiceChannel::DeskPos->value;

        $this->signedPost($payload, channel: StatutoryInvoiceChannel::DeskPos->value, secret: 'anything')
            ->assertUnauthorized();

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_authentication_failure_does_not_ingest(): void
    {
        $this->signedPost($this->payload(), signature: 'deadbeef')
            ->assertUnauthorized();

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertGreaterThan(0, ChannelIngestAttempt::query()->count());
    }

    public function test_stale_timestamp_is_replay_protected(): void
    {
        $this->signedPost($this->payload(), timestamp: (string) (time() - 301))
            ->assertUnauthorized()
            ->assertJsonPath('status', 'replay');

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_channel_with_empty_secret_cannot_ingest(): void
    {
        $payload = $this->payload();
        $payload['channel'] = StatutoryInvoiceChannel::RdServiceNet->value;

        $this->signedPost($payload, channel: StatutoryInvoiceChannel::RdServiceNet->value, secret: 'guess')
            ->assertUnauthorized();
    }

    public function test_configured_test_series_still_does_not_mint(): void
    {
        config([
            'statutory_invoices.series_code' => 'TEST',
            'statutory_invoices.number_format' => '{series}-{seq:5}',
        ]);

        $this->signedPost($this->payload())
            ->assertCreated()
            ->assertJsonPath('status', CommerceOrderStatus::InvoicePending->value)
            ->assertJsonPath('invoice', null);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_auto_issue_flag_fails_closed(): void
    {
        config(['channel_ingest.auto_issue_invoice' => true]);

        $this->signedPost($this->payload())->assertStatus(422);
        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_independent_source_ids_create_separate_orders(): void
    {
        $a = $this->payload('RD-1001');
        $b = $this->payload('RD-1002');

        $this->signedPost($a)->assertCreated();
        $this->signedPost($b)->assertCreated();

        $this->assertSame(2, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_missing_tax_fields_are_accepted_but_not_eligible(): void
    {
        $payload = $this->payload();
        unset($payload['seller_gstin'], $payload['place_of_supply_state']);
        $payload['lines'][0]['hsn_sac'] = null;
        unset($payload['lines'][0]['taxable_value'], $payload['lines'][0]['tax_total']);

        $this->signedPost($payload)
            ->assertCreated()
            ->assertJsonPath('status', CommerceOrderStatus::Validated->value)
            ->assertJsonPath('invoice_eligible', false);

        $order = CommerceOrder::query()->first();
        $this->assertNull($order?->taxable_value);
        $this->assertNull($order?->items->first()?->hsn_sac);
    }

    public function test_wrong_idempotency_header_is_rejected(): void
    {
        $this->signedPost($this->payload(), idempotencyKey: 'other-system:1')
            ->assertStatus(422);

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_matching_idempotency_header_is_accepted(): void
    {
        $this->signedPost(
            $this->payload(),
            idempotencyKey: 'statutory:rdservice_in:commerce_order:RD-1001',
        )->assertCreated();
    }

    public function test_failed_outer_transaction_rolls_back_ingest(): void
    {
        try {
            DB::transaction(function (): void {
                $this->app->make(ChannelIngestService::class)->ingest(
                    $this->payload(),
                    StatutoryInvoiceChannel::RdServiceIn,
                );
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(
        array $payload,
        ?string $channel = null,
        ?string $secret = self::SECRET,
        ?string $signature = null,
        ?string $timestamp = null,
        ?string $idempotencyKey = null,
    ) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= (string) time();
        $channel ??= (string) ($payload['channel'] ?? StatutoryInvoiceChannel::RdServiceIn->value);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $channel,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => $signature ?? (new ChannelIngestAuthenticator)->signature($timestamp, $body, $secret),
        ];
        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], $headers, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $sourceId = 'RD-1001'): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Walk-in',
                'phone' => '9000000001',
                'gstin' => null,
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'RADium Desk',
            'place_of_supply_state' => 'Delhi',
            'lines' => [
                [
                    'description' => 'RD Service',
                    'sku' => 'RD-SVC',
                    'qty' => 1,
                    'unit_price' => 100,
                    'hsn_sac' => '998313',
                    'gst_percentage' => 18,
                    'taxable_value' => 100,
                    'tax_total' => 18,
                    'line_total' => 118,
                ],
            ],
        ];
    }
}
