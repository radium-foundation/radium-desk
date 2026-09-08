<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\HardwareFulfilmentSerial;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use App\Services\Shipping\NullShiprocketGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwareFulfilmentWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Http::fake();
        Http::preventStrayRequests();
        config([
            'shipping.enabled' => false,
            'shipping.provider' => 'none',
            'shipping.http_enabled' => false,
        ]);

        $this->creator = User::factory()->create(['is_active' => true]);
        $this->operator = User::factory()->create(['is_active' => true]);
        $this->operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_index_is_work_queue_with_cutoff_to_now_range(): void
    {
        $candidate = $this->deskOrder('RDE970001', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $ready = $this->fulfilment('RDE970421', HardwareFulfilmentState::InvoiceIssued, [
            'invoice' => true,
            'serial' => true,
        ]);

        $from = HardwareFulfilmentEligibility::cutoffInstant()->format('Y-m-d H:i');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index'))
            ->assertOk()
            ->assertSee('Work Queue')
            ->assertSee('Active date range (IST): '.$from)
            ->assertSee('RDE970001')
            ->assertSee('Awaiting Fulfilment')
            ->assertSee('RDE970421')
            ->assertSee('Ready for Shipment')
            ->assertSee('Create Shipment')
            ->assertSee(route('orders.show', $candidate), false)
            ->assertSee(route('inventory.hardware-fulfilments.show', $ready), false)
            ->assertDontSee('Create All')
            ->assertDontSee('Create fulfilment')
            ->assertDontSee('Initialize fulfilment')
            ->assertDontSee(route('inventory.hardware-fulfilments.shipment.store', $ready), false);

        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertInstanceOf(NullShiprocketGateway::class, app(ShiprocketGateway::class));
        Http::assertNothingSent();
    }

    public function test_work_queue_classifies_operational_stages_and_exclusions(): void
    {
        $awaiting = $this->deskOrder('RDE970101', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
            'customer_name' => 'Awaiting Buyer',
            'product_name' => 'MFS 110',
        ]);
        $this->fulfilment('RDE970102', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE970103', HardwareFulfilmentState::SerialsAllocated, ['serial' => true]);
        $this->fulfilment('RDE970104', HardwareFulfilmentState::InvoiceIssued, [
            'serial' => true,
            'invoice' => true,
        ]);
        $this->fulfilment('RDE970105', HardwareFulfilmentState::ShipmentCreated, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
        ]);
        $this->fulfilment('RDE970106', HardwareFulfilmentState::AwbAssigned, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
            'awb' => 'AWB970106',
        ]);
        $this->fulfilment('RDE970107', HardwareFulfilmentState::AwbAssigned, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
            'awb' => 'AWB970107',
            'label' => true,
        ]);
        $this->fulfilment('RDE970108', HardwareFulfilmentState::AwbAssigned, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
            'awb' => 'AWB970108',
            'label' => true,
            'evidence' => true,
        ]);
        $this->fulfilment('RDE970109', HardwareFulfilmentState::AwbAssigned, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
            'awb' => 'AWB970109',
            'label' => true,
            'evidence' => true,
            'pickup' => true,
        ]);
        $this->fulfilment('RDE970110', HardwareFulfilmentState::AwbAssigned, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
            'awb' => 'AWB970110',
            'label' => true,
            'evidence' => true,
            'pickup' => true,
            'manifest' => true,
            'ready' => true,
        ]);
        $this->fulfilment('RDE970111', HardwareFulfilmentState::Shipped, [
            'serial' => true,
            'invoice' => true,
            'bound' => true,
            'awb' => 'AWB970111',
            'label' => true,
            'evidence' => true,
            'pickup' => true,
            'manifest' => true,
            'ready' => true,
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE255714', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE313554', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RIN970199', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE970198', [
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE970197', [
            'cashfree_payment_id' => 'paid',
            'serial_number' => '105970197',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE970196', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-04 17:00:00',
        ]);
        $this->deskOrder('RDE970195', [
            'cashfree_payment_id' => 'paid',
            'created_at' => Carbon::now('UTC')->addDay(),
        ]);

        $beforeFulfilments = HardwareFulfilment::query()->count();
        $beforeShipments = Shipment::query()->count();

        $html = $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'work']))
            ->assertOk()
            ->assertSee('RDE970101')
            ->assertSee('Awaiting Buyer')
            ->assertSee('RDE970102')
            ->assertSee('Allocate Serial')
            ->assertSee('RDE970103')
            ->assertSee('Issue Invoice')
            ->assertSee('RDE970104')
            ->assertSee('Create Shipment')
            ->assertSee('RDE970105')
            ->assertSee('Assign AWB')
            ->assertSee('RDE970106')
            ->assertSee('Generate/Print Label')
            ->assertSee('RDE970107')
            ->assertSee('Record Package / Label-Applied Evidence')
            ->assertSee('RDE970108')
            ->assertSee('Request Pickup')
            ->assertSee('RDE970109')
            ->assertSee('Generate Manifest')
            ->assertSee('RDE970110')
            ->assertSee('Ready for Pickup')
            ->assertSee('RDE970111')
            ->assertSee('Completed')
            ->assertSee(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0])
            ->assertSee('RDE255714')
            ->assertSee('RDE313554')
            ->assertSee(HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0])
            ->assertSee('Blocked / Review Required')
            ->assertDontSee('RIN970199')
            ->assertDontSee('RDE970198')
            ->assertDontSee('RDE970197')
            ->assertDontSee('RDE970196')
            ->assertDontSee('RDE970195')
            ->assertDontSee('Create All')
            ->assertDontSee('Create fulfilment')
            ->getContent();

        $this->assertStringContainsString('Awaiting Fulfilment', $html);
        $this->assertStringContainsString('Awaiting Serial', $html);
        $this->assertStringContainsString('Awaiting Invoice', $html);
        $this->assertStringContainsString('Ready for Shipment', $html);
        $this->assertStringContainsString('AWB Pending', $html);
        $this->assertStringContainsString('Label/Packing Pending', $html);
        $this->assertStringContainsString('Pickup/Manifest Pending', $html);
        $this->assertStringContainsString(route('orders.show', $awaiting), $html);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'work',
                'stage' => HardwareFulfilmentOperationalStage::BlockedReview->value,
            ]))
            ->assertOk()
            ->assertSee('RDE255714')
            ->assertDontSee('RDE970101')
            ->assertDontSee('RDE970104');

        $this->assertSame($beforeFulfilments, HardwareFulfilment::query()->count());
        $this->assertSame($beforeShipments, Shipment::query()->count());
        Http::assertNothingSent();
    }

    public function test_date_boundary_and_search_do_not_create_records(): void
    {
        $this->deskOrder('RDE970201', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-05 00:00:00',
        ]);
        $this->deskOrder('RDE970202', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-04 18:29:00',
        ]);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'work',
                'from' => '2026-09-05',
                'to' => '2026-09-05',
                'order' => 'RDE970201',
            ]))
            ->assertOk()
            ->assertSee('RDE970201')
            ->assertDontSee('RDE970202')
            ->assertSee('2026-09-05');

        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        Http::assertNothingSent();
    }

    public function test_work_queue_uses_ist_wall_clock_against_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 17:17:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE));

        $this->deskOrder('RDE970301', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-05 00:00:00',
        ]);
        $this->deskOrder('RDE970302', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-04 23:59:59',
        ]);
        $this->deskOrder('RDE970303', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-05 00:00:01',
        ]);
        $this->deskOrder('RDE970304', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-04 18:45:00',
        ]);
        $this->deskOrder('RDE970305', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-08 16:30:00',
        ]);
        $this->deskOrder('RDE970306', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-08 17:30:00',
        ]);

        $utcInterpretedAsIst = $this->deskOrder('RDE970307', [
            'cashfree_payment_id' => 'paid',
        ]);
        $utcInterpretedAsIst->forceFill([
            'created_at' => Carbon::parse('2026-09-08 11:00:00', 'UTC'),
        ])->save();

        $afternoon = [
            'RDE318477' => '2026-09-08 13:20:10',
            'RDE318482' => '2026-09-08 14:25:14',
            'RDE318486' => '2026-09-08 15:48:26',
            'RDE318487' => '2026-09-08 15:46:44',
            'RDE318489' => '2026-09-08 15:58:20',
            'RDE318490' => '2026-09-08 16:24:55',
        ];
        foreach ($afternoon as $orderId => $createdAt) {
            $this->deskOrder($orderId, [
                'cashfree_payment_id' => 'paid',
                'created_at' => $createdAt,
            ]);
        }

        $existing = $this->fulfilment('RDE318421', HardwareFulfilmentState::InvoiceIssued, [
            'serial' => true,
            'invoice' => true,
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RDE255714', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder(HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0], [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $this->deskOrder('RIN970399', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-08 16:00:00',
        ]);

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $sourceIds = app(HardwareFulfilmentWorkQueue::class)
            ->paginate($from, $to)
            ->getCollection()
            ->pluck('sourceId');
        $listed = $sourceIds->all();

        $this->assertSame(1, $sourceIds->filter(fn (string $id): bool => $id === 'RDE318421')->count());
        $this->assertContains('RDE970301', $listed);
        $this->assertContains('RDE970303', $listed);
        $this->assertContains('RDE970305', $listed);
        $this->assertContains('RDE970307', $listed);
        $this->assertContains('RDE318477', $listed);
        $this->assertContains('RDE318482', $listed);
        $this->assertContains('RDE318486', $listed);
        $this->assertContains('RDE318487', $listed);
        $this->assertContains('RDE318489', $listed);
        $this->assertContains('RDE318490', $listed);
        $this->assertNotContains('RDE970302', $listed);
        $this->assertNotContains('RDE970304', $listed);
        $this->assertNotContains('RDE970306', $listed);
        $this->assertNotContains('RIN970399', $listed);

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'work']))
            ->assertOk()
            ->assertSee('RDE318477')
            ->assertSee('RDE318421')
            ->assertSee('Ready for Shipment')
            ->assertSee(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0])
            ->assertSee('RDE255714')
            ->assertSee(HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0])
            ->assertDontSee('RIN970399')
            ->assertSee('Active date range (IST): 2026-09-05 00:00');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'awaiting']))
            ->assertOk()
            ->assertDontSee('RDE318421');

        $this->assertSame($existing->id, HardwareFulfilment::query()->where('source_id', 'RDE318421')->value('id'));
        $this->assertSame(1, HardwareFulfilment::query()->count());
        Http::assertNothingSent();
    }

    public function test_unauthorized_user_cannot_open_work_queue(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'work']))
            ->assertForbidden();

        $this->assertSame(0, HardwareFulfilment::query()->count());
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function deskOrder(string $orderId, array $overrides = []): Order
    {
        if (filled($overrides['cashfree_payment_id'] ?? null)) {
            $overrides['cashfree_payment_id'] = 'cf_'.$orderId;
        }

        $order = Order::query()->create(array_merge([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $this->creator->id,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $order->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function fulfilment(string $sourceId, HardwareFulfilmentState $state, array $opts = []): HardwareFulfilment
    {
        $order = $this->deskOrder($sourceId, [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
            'customer_name' => 'Hardware Buyer '.$sourceId,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Hardware Buyer '.$sourceId,
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS110',
            'qty' => 1,
            'unit_price' => 2549,
            'gst_percentage' => 18,
            'taxable_value' => 2160.17,
            'tax_total' => 388.83,
            'line_total' => 2549,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'support_order_id' => $order->id,
            'ingested_at' => now(),
            'ready_for_pickup_at' => ($opts['ready'] ?? false) ? now() : null,
        ]);

        if ($opts['serial'] ?? false) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-'.$sourceId,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        if ($opts['invoice'] ?? false) {
            $invoice = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-'.$sourceId,
                'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
                'status' => StatutoryInvoiceStatus::Issued,
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);
            $fulfilment->forceFill(['statutory_invoice_id' => $invoice->id])->save();
            $commerce->forceFill(['statutory_invoice_id' => $invoice->id])->save();
        }

        if (($opts['bound'] ?? false) || ($opts['awb'] ?? null) || ($opts['failed'] ?? false)) {
            $shipment = Shipment::query()->create([
                'shipment_no' => 'HW-'.$sourceId,
                'commerce_order_id' => $commerce->id,
                'hardware_fulfilment_id' => $fulfilment->id,
                'provider' => 'shiprocket',
                'status' => ($opts['awb'] ?? null) ? ShipmentStatus::AwbAssigned : ShipmentStatus::Created,
                'external_order_id' => ($opts['bound'] ?? false) ? 'ext-'.$sourceId : null,
                'external_shipment_id' => ($opts['bound'] ?? false) ? 'shp-'.$sourceId : null,
                'awb' => $opts['awb'] ?? null,
                'label_url' => ($opts['label'] ?? false) ? '/labels/'.$sourceId.'.pdf' : null,
                'pickup_requested_at' => ($opts['pickup'] ?? false) ? now() : null,
                'manifest_id' => ($opts['manifest'] ?? false) ? 'man-'.$sourceId : null,
                'idempotency_key' => 'ship:'.$sourceId,
                'correlation_id' => (string) Str::uuid(),
            ]);
            $fulfilment->forceFill([
                'shipment_id' => $shipment->id,
                'awb' => $opts['awb'] ?? null,
            ])->save();
        }

        if ($opts['evidence'] ?? false) {
            HardwareFulfilmentPackageEvidence::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied,
                'disk' => 'local',
                'path' => 'evidence/'.$sourceId.'.jpg',
                'original_filename' => $sourceId.'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 12,
                'uploaded_by_user_id' => $this->operator->id,
                'uploaded_at' => now(),
            ]);
        }

        return $fulfilment->fresh(['commerceOrder.items', 'serials', 'shipment']) ?? $fulfilment;
    }
}
