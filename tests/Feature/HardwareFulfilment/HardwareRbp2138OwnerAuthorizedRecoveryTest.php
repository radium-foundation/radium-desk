<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\HardwareRecoveredFulfilmentAuthorization as AuthorizationRow;
use App\Models\Order;
use App\Models\User;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\HardwareOwnerAuthorizedExistingCommerceProfiles;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareRbp2138OwnerAuthorizedRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private HardwareFulfilmentIsolatedWorkflowService $isolated;

    private HardwareRecoveredFulfilmentAuthorization $authorizations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        config([
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.worker_may_mint' => false,
        ]);

        $this->isolated = app(HardwareFulfilmentIsolatedWorkflowService::class);
        $this->authorizations = app(HardwareRecoveredFulfilmentAuthorization::class);
    }

    public function test_channel_ingest_never_auto_opens_rbp2138(): void
    {
        $request = $this->rbpIngestRequest('RBP2138');

        $this->assertFalse(HardwareFulfilmentEligibility::shouldOpenRecord($request));
        $this->assertFalse(HardwareFulfilmentEligibility::looksLikeHardwareSourceId('RBP2138'));
    }

    public function test_unauthorized_rbp2138_cannot_ingest_from_existing_commerce(): void
    {
        $this->rbp2138Fixture();

        try {
            $this->isolated->run(identifier: 'RBP2138', step: 'ingest');
            $this->fail('Unauthorized RBP2138 ingest must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Owner authorization', collect($exception->errors())->flatten()->first() ?? '');
        }

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_unauthorized_rbp9999_is_rejected_for_authorization_and_ingest(): void
    {
        $this->rbp2138Fixture('RBP9999', 'CO-999999', '6998469576');

        $result = $this->authorizations->authorizeOne(
            CommerceOrder::query()->where('source_id', 'RBP9999')->firstOrFail(),
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_EXISTING_COMMERCE,
            'test',
        );
        $this->assertFalse($result['ok']);

        try {
            $this->isolated->run(identifier: 'RBP9999', step: 'ingest');
            $this->fail('Unknown RBP must not ingest.');
        } catch (ValidationException) {
            // expected
        }
    }

    public function test_owner_authorized_rbp2138_opens_one_hf_idempotently_without_serial_allocation(): void
    {
        $order = $this->rbp2138Fixture();
        $support = Order::query()->where('order_id', 'RBP2138')->firstOrFail();
        $support->forceFill(['serial_number' => '11119634'])->save();

        $auth = $this->authorizations->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_EXISTING_COMMERCE,
            'RadiumDesk-P-14-59',
        );
        $this->assertTrue($auth['ok']);

        $first = $this->isolated->run(identifier: 'RBP2138', step: 'ingest');
        $second = $this->isolated->run(identifier: 'RBP2138', step: 'ingest');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(1, CommerceOrder::query()->count());

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame('RBP2138', $fulfilment->source_id);
        $event = HardwareFulfilmentEvent::query()->firstOrFail();
        $this->assertSame('recovered_commerce_ingest', $event->payload['reason'] ?? null);
        $this->assertContains(
            $fulfilment->state,
            [HardwareFulfilmentState::Ingested, HardwareFulfilmentState::ReadyForFulfilment],
        );
        $this->assertSame('11119634', $support->fresh()->serial_number);
        $this->assertNotSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->state);
    }

    public function test_mismatched_payment_reference_rejects_authorization(): void
    {
        $order = $this->rbp2138Fixture(paymentReference: 'wrong-payment-ref');

        $result = $this->authorizations->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_EXISTING_COMMERCE,
            'test',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(0, AuthorizationRow::query()->count());
    }

    public function test_artisan_authorize_command_accepts_only_allowlisted_pair(): void
    {
        $this->rbp2138Fixture();

        $this->artisan('desk:authorize-existing-commerce-fulfilment', [
            'source_id' => 'RBP2138',
            'commerce_order_no' => 'CO-022155',
            '--actor' => 'RadiumDesk-P-14-59',
        ])->assertSuccessful();

        $this->assertSame(1, AuthorizationRow::query()->count());
        $row = AuthorizationRow::query()->firstOrFail();
        $this->assertSame('RBP2138', $row->source_id);
        $this->assertSame(
            HardwareOwnerAuthorizedExistingCommerceProfiles::PURPOSE,
            $row->purpose,
        );
    }

    private function rbpIngestRequest(string $sourceId): ChannelOrderIngestRequest
    {
        return new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: $sourceId,
            lines: [
                new ChannelOrderLineDraft(
                    description: 'Mantra MFS110 L1',
                    qty: 1,
                    unitPrice: 2499,
                    sku: '946',
                    shippingLineKind: HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                    requiresShipping: true,
                    modelId: 946,
                ),
            ],
            paymentStatus: 'paid',
            currency: 'INR',
        );
    }

    private function rbp2138Fixture(
        string $sourceId = 'RBP2138',
        string $orderNo = 'CO-022155',
        string $paymentReference = '6998469575',
    ): CommerceOrder {
        Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS110',
            'status' => 'active',
            'created_by' => User::factory()->create(['is_active' => true])->id,
            'cashfree_payment_id' => $paymentReference,
            'payment_amount' => 2499,
        ]);

        $commerce = CommerceOrder::query()->create([
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
            'payment_reference' => $paymentReference,
            'currency' => 'INR',
            'customer_name' => 'RBP buyer',
            'ordered_at' => '2026-11-14 12:03:29',
            'paid_at' => '2026-11-14 12:06:13',
            'received_at' => now(),
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'Mantra MFS110 L1',
            'qty' => 1,
            'unit_price' => 2499,
            'gst_percentage' => 18,
            'taxable_value' => 2117.8,
            'tax_total' => 381.2,
            'line_total' => 2499,
        ]);

        return $commerce->fresh(['items']);
    }
}
