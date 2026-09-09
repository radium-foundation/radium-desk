<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Console\Commands\AuthorizeRecoveredFulfilmentCommand;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\HardwareRecoveredFulfilmentAuthorization as AuthorizationRow;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareRecoveredFulfilmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private HardwareFulfilmentIsolatedWorkflowService $isolated;

    private HardwareRecoveredFulfilmentAuthorization $authorizations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolated = app(HardwareFulfilmentIsolatedWorkflowService::class);
        $this->authorizations = app(HardwareRecoveredFulfilmentAuthorization::class);
    }

    public function test_frozen_list_is_unchanged_and_unauthorized_sources_stay_frozen_for_fulfilment(): void
    {
        $this->assertSame([
            'RDE318360',
            'RDE318367',
            'RDE318378',
            'RDE318379',
            'RDE318382',
            'RDE318388',
            'RDE318391',
        ], HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS);

        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId));
        }
    }

    public function test_authorized_recovered_order_is_not_frozen_for_fulfilment_and_opens_one_hf(): void
    {
        $order = $this->recoveredCommerce('RDE318367', 'CO-000761', [
            ['model_id' => 946, 'qty' => 1, 'sku' => '946'],
        ]);
        $this->authorizations->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId('RDE318367'));
        $this->assertFalse(HardwareFulfilmentEligibility::isFrozenForFulfilment('RDE318367', $order));

        $first = $this->isolated->run(identifier: 'RDE318367', step: 'ingest');
        $second = $this->isolated->run(identifier: 'RDE318367', step: 'ingest');

        $this->assertSame(HardwareFulfilmentState::Ingested->value, $first['state']);
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(
            'recovered_commerce_ingest',
            HardwareFulfilmentEvent::query()->first()?->payload['reason'] ?? null,
        );
        $this->assertSame(1, (int) $order->fresh()->items->first()?->qty);
        $this->assertSame(946, (int) $order->fresh()->items->first()?->model_id);
    }

    public function test_authorized_commerce_reaches_ready_through_existing_state_machine(): void
    {
        $order = $this->recoveredCommerce('RDE318379', 'CO-000756', [
            ['model_id' => 1006, 'qty' => 1, 'sku' => '1006'],
        ]);
        $this->authorizations->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $this->isolated->run(identifier: 'RDE318379', step: 'ingest');
        $ready = $this->isolated->run(identifier: 'RDE318379', step: 'ready');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment->value, $ready['state']);
        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
        $this->assertNotNull($fulfilment->ready_at);
        $this->assertSame(0, $fulfilment->serials()->count());
        $this->assertNull($fulfilment->statutory_invoice_id);
        $this->assertNull($fulfilment->shipment_id);
    }

    public function test_payload_replay_is_rejected_and_does_not_create_second_commerce(): void
    {
        $order = $this->recoveredCommerce('RDE318382', 'CO-000759', [
            ['model_id' => 951, 'qty' => 1, 'sku' => '951'],
        ]);
        $this->authorizations->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        try {
            $this->isolated->run(
                identifier: 'RDE318382',
                step: 'ingest',
                payload: $this->boxPayload('RDE318382'),
            );
            $this->fail('Authorized recovered ingest must refuse a Box payload.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('payload replay', collect($exception->errors())->flatten()->first() ?? '');
        }

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame((int) $order->id, (int) CommerceOrder::query()->value('id'));
    }

    public function test_recovered_path_without_commerce_fails_closed(): void
    {
        $this->expectException(ValidationException::class);
        $this->isolated->run(identifier: 'RDE318388', step: 'ingest');
    }

    public function test_unauthorized_frozen_commerce_cannot_open_hf(): void
    {
        $this->recoveredCommerce('RDE318391', 'CO-000758', [
            ['model_id' => 946, 'qty' => 1, 'sku' => '946'],
        ]);

        $this->assertTrue(HardwareFulfilmentEligibility::isFrozenForFulfilment('RDE318391'));

        try {
            $this->isolated->run(identifier: 'RDE318391', step: 'ingest');
            $this->fail('Unauthorized frozen commerce must not open HF.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(1, CommerceOrder::query()->count());
    }

    public function test_identity_mismatch_source_commerce_and_channel_are_rejected(): void
    {
        $left = $this->recoveredCommerce('RDE318360', 'CO-000757', [
            ['model_id' => 951, 'qty' => 2, 'sku' => '951'],
            ['model_id' => 1006, 'qty' => 1, 'sku' => '1006'],
        ]);
        $right = $this->recoveredCommerce('RDE318378', 'CO-000760', [
            ['model_id' => 930, 'qty' => 1, 'sku' => '930'],
        ]);

        AuthorizationRow::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE318360',
            'commerce_order_id' => $right->id,
            'commerce_order_no' => $left->order_no,
            'purpose' => HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'status' => AuthorizationRow::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'authorized_by' => 'test',
        ]);
        $this->assertFalse($this->authorizations->isAuthorized($left));

        AuthorizationRow::query()->where('source_id', 'RDE318360')->delete();
        AuthorizationRow::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE318378',
            'commerce_order_id' => $left->id,
            'commerce_order_no' => $left->order_no,
            'purpose' => HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'status' => AuthorizationRow::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'authorized_by' => 'test',
        ]);
        $this->assertFalse($this->authorizations->isAuthorized($left));

        AuthorizationRow::query()->where('commerce_order_id', $left->id)->delete();
        AuthorizationRow::query()->create([
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE318360',
            'commerce_order_id' => $left->id,
            'commerce_order_no' => $left->order_no,
            'purpose' => HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'status' => AuthorizationRow::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'authorized_by' => 'test',
        ]);
        $this->assertFalse($this->authorizations->isAuthorized($left));
        $this->assertTrue(HardwareFulfilmentEligibility::isFrozenForFulfilment('RDE318360', $left));
    }

    public function test_hold_and_blocked_sources_cannot_be_authorized_or_ingested(): void
    {
        foreach (['RDE318438', 'RDE255714', 'RDE313554'] as $sourceId) {
            $order = $this->recoveredCommerce($sourceId, 'CO-HOLD-'.$sourceId, [
                ['model_id' => 946, 'qty' => 1, 'sku' => '946'],
            ]);
            $result = $this->authorizations->authorizeOne(
                $order,
                HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
                'test',
            );
            $this->assertFalse($result['ok'], $sourceId.' must not be authorizable.');
            $this->assertTrue(HardwareFulfilmentEligibility::isHoldSourceId($sourceId));

            try {
                $this->isolated->run(identifier: $sourceId, step: 'ingest');
                $this->fail($sourceId.' recovered ingest must fail.');
            } catch (ValidationException) {
                // expected
            }
        }

        $blocked = $this->recoveredCommerce('RDE318400', 'CO-000740', [
            ['model_id' => 946, 'qty' => 1, 'sku' => '946'],
        ]);
        $blockedResult = $this->authorizations->authorizeOne(
            $blocked,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );
        $this->assertFalse($blockedResult['ok']);
        $this->assertTrue(HardwareFulfilmentEligibility::isBlockedUntilAuthorized('RDE318400'));

        try {
            $this->isolated->run(identifier: 'RDE318400', step: 'ingest');
            $this->fail('RDE318400 recovered ingest must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, AuthorizationRow::query()->count());
    }

    public function test_multiline_rde318360_preserves_both_hardware_lines(): void
    {
        $order = $this->recoveredCommerce('RDE318360', 'CO-000757', [
            ['model_id' => 951, 'qty' => 2, 'sku' => '951', 'line_no' => 1],
            ['model_id' => 1006, 'qty' => 1, 'sku' => '1006', 'line_no' => 2],
        ]);
        $this->authorizations->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $this->isolated->run(identifier: 'RDE318360', step: 'ingest');

        $fresh = $order->fresh(['items']);
        $this->assertSame(1, CommerceOrder::query()->where('source_id', 'RDE318360')->count());
        $this->assertSame(1, HardwareFulfilment::query()->where('source_id', 'RDE318360')->count());
        $this->assertCount(2, $fresh->items);
        $this->assertSame(951, (int) $fresh->items[0]->model_id);
        $this->assertSame(2, (int) $fresh->items[0]->qty);
        $this->assertSame(1006, (int) $fresh->items[1]->model_id);
        $this->assertSame(1, (int) $fresh->items[1]->qty);
        $this->assertSame(HardwareFulfilmentState::Ingested, HardwareFulfilment::query()->firstOrFail()->state);
    }

    public function test_initial_seven_command_authorizes_only_matching_pairs(): void
    {
        foreach (AuthorizeRecoveredFulfilmentCommand::INITIAL_PRODUCTION_PAIRS as $sourceId => $orderNo) {
            $lines = $sourceId === 'RDE318360'
                ? [
                    ['model_id' => 951, 'qty' => 2, 'sku' => '951', 'line_no' => 1],
                    ['model_id' => 1006, 'qty' => 1, 'sku' => '1006', 'line_no' => 2],
                ]
                : [['model_id' => 946, 'qty' => 1, 'sku' => '946']];
            $this->recoveredCommerce($sourceId, $orderNo, $lines);
        }

        $this->artisan('desk:authorize-recovered-fulfilment', [
            '--initial-seven' => true,
            '--actor' => 'RadiumDesk-P-07-09-143',
        ])->assertSuccessful();

        $this->assertSame(7, AuthorizationRow::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(
            ['RDE318360', 'RDE318367', 'RDE318378', 'RDE318379', 'RDE318382', 'RDE318388', 'RDE318391'],
            AuthorizationRow::query()->orderBy('source_id')->pluck('source_id')->all(),
        );
    }

    /**
     * @param  list<array{model_id: int, qty: int, sku: string, line_no?: int}>  $lines
     */
    private function recoveredCommerce(string $sourceId, string $orderNo, array $lines): CommerceOrder
    {
        Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'Recovered hardware '.$sourceId,
            'status' => 'active',
            'created_by' => User::factory()->create(['is_active' => true])->id,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'payment_amount' => 1000,
        ]);

        $order = CommerceOrder::query()->create([
            'order_no' => $orderNo,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer_name' => 'Recovered Buyer '.$sourceId,
            'ordered_at' => '2026-09-06 10:00:00',
            'paid_at' => '2026-09-06 10:05:00',
            'received_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $order->id,
                'line_no' => $line['line_no'] ?? ($index + 1),
                'sku' => $line['sku'],
                'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                'requires_shipping' => true,
                'model_id' => $line['model_id'],
                'description' => 'Line '.$sourceId.' '.$line['model_id'],
                'hsn_sac' => '84716050',
                'qty' => $line['qty'],
                'unit_price' => 1000,
                'gst_percentage' => 18,
                'taxable_value' => 847.46,
                'tax_total' => 152.54,
                'line_total' => 1000 * $line['qty'],
            ]);
        }

        return $order->fresh(['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function boxPayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_replay_'.$sourceId,
            'currency' => 'INR',
            'ordered_at' => '2026-09-06T10:00:00+05:30',
            'customer' => [
                'name' => 'Replay Buyer',
                'phone' => '9000000099',
            ],
            'lines' => [[
                'description' => 'Replay line',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049.00,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];
    }
}
