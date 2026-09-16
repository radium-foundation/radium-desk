<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareDashboardQueue;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareOperationsSection;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class HardwareFulfilmentBulkDocumentsTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private FakeShiprocketGateway $fake;

    private User $admin;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        Http::fake();
        Http::preventStrayRequests();
        $this->fake = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->fake);
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.callback.enabled' => false,
            'shipping.enabled' => true,
            'shipping.provider' => 'test',
            'shipping.http_enabled' => false,
            'shipping.pickup_locations.delhi' => 'RADDELHI',
            'shipping.pickup_postcodes.delhi' => '110001',
            'shipping.courier_options_ttl_seconds' => 900,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN, ['DELHI-RETAIL', 'MUMBAI']);
    }

    public function test_bulk_labels_single_shipment_returns_combined_pdf_url(): void
    {
        $fulfilment = $this->awbFulfilment('RDE950001');
        $shipment = Shipment::query()->firstOrFail();
        $awbsBefore = $this->fake->awbs;
        $createsBefore = $this->fake->creates;

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertRedirect('https://fake.local/labels/'.$shipment->external_shipment_id);

        $this->assertSame(1, $this->fake->labels);
        $this->assertSame([(string) $shipment->external_shipment_id], $this->fake->lastLabelBatchShipmentIds);
        $this->assertNotNull($shipment->fresh()->label_url);
        $this->assertSame($awbsBefore, $this->fake->awbs);
        $this->assertSame($createsBefore, $this->fake->creates);
    }

    public function test_bulk_labels_multiple_shipments_use_one_provider_call_and_preserve_order(): void
    {
        $first = $this->awbFulfilment('RDE950002');
        $second = $this->awbFulfilment('RDE950003');
        $third = $this->awbFulfilment('RDE950004');

        $shipments = Shipment::query()->orderBy('id')->get();
        $this->assertCount(3, $shipments);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$third->id, $first->id, $second->id],
            ])
            ->assertRedirect('https://fake.local/labels/'.implode(',', [
                (string) $shipments[2]->external_shipment_id,
                (string) $shipments[0]->external_shipment_id,
                (string) $shipments[1]->external_shipment_id,
            ]));

        $this->assertSame(1, $this->fake->labels);
        $this->assertSame([
            (string) $shipments[2]->external_shipment_id,
            (string) $shipments[0]->external_shipment_id,
            (string) $shipments[1]->external_shipment_id,
        ], $this->fake->lastLabelBatchShipmentIds);
    }

    public function test_bulk_labels_exclude_missing_awb_and_continue_with_valid_selection(): void
    {
        $ready = $this->awbFulfilment('RDE950005');
        $missingAwb = $this->invoicedFulfilment('RDE950006', 'DELHI-RETAIL');
        $this->selectTestCourier($missingAwb, $this->admin);
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.shipment.store', $missingAwb->fresh()));

        $shipment = Shipment::query()->where('hardware_fulfilment_id', $ready->id)->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->from(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'pickup']))
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$missingAwb->id, $ready->id],
            ]);

        $response->assertRedirect('https://fake.local/labels/'.$shipment->external_shipment_id)
            ->assertSessionHas('hardware_bulk_document_partial')
            ->assertSessionHas('status');

        $partial = session('hardware_bulk_document_partial');
        $this->assertSame(['RDE950005'], $partial['succeeded']);
        $this->assertCount(1, $partial['excluded']);
        $this->assertSame('RDE950006', $partial['excluded'][0]['source_id']);
        $this->assertSame(1, $this->fake->labels);
        $this->assertNotNull($shipment->fresh()->label_url);
        $this->assertNull(Shipment::query()->where('hardware_fulfilment_id', $missingAwb->id)->first()?->label_url);
    }

    public function test_bulk_labels_rejects_unauthorized_user(): void
    {
        $fulfilment = $this->awbFulfilment('RDE950007');
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->fake->labels);
    }

    public function test_bulk_labels_provider_failure_does_not_mutate_shipments(): void
    {
        $fulfilment = $this->awbFulfilment('RDE950008');
        $this->fake->nextLabelMode = 'rejected';

        $this->actingAs($this->admin)
            ->from(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'pickup']))
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('shipping');

        $this->assertNull(Shipment::query()->firstOrFail()->label_url);
        $this->assertSame(1, $this->fake->labels);
    }

    public function test_bulk_manifest_uses_one_provider_call_for_multiple_shipments(): void
    {
        $first = $this->pickupReadyFulfilment('RDE950009');
        $second = $this->pickupReadyFulfilment('RDE950010');

        $shipments = Shipment::query()->orderBy('id')->get();
        $this->assertCount(2, $shipments);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.manifest'), [
                'fulfilment_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect('https://fake.local/manifests/'.implode(',', [
                (string) $shipments[0]->external_shipment_id,
                (string) $shipments[1]->external_shipment_id,
            ]));

        $this->assertSame(1, $this->fake->manifests);
        $this->assertSame([
            (string) $shipments[0]->external_shipment_id,
            (string) $shipments[1]->external_shipment_id,
        ], $this->fake->lastManifestBatchShipmentIds);
        $this->assertNotNull($shipments[0]->fresh()->manifest_url);
        $this->assertNotNull($shipments[1]->fresh()->manifest_url);
    }

    public function test_bulk_manifest_excludes_shipment_without_pickup(): void
    {
        $ready = $this->pickupReadyFulfilment('RDE950011');
        $noPickup = $this->awbFulfilment('RDE950012');
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.label.store', $noPickup->fresh()));

        $readyShipment = Shipment::query()->where('hardware_fulfilment_id', $ready->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'pickup']))
            ->post(route('inventory.hardware-fulfilments.bulk.manifest'), [
                'fulfilment_ids' => [$noPickup->id, $ready->id],
            ])
            ->assertRedirect('https://fake.local/manifests/'.(string) $readyShipment->external_shipment_id)
            ->assertSessionHas('hardware_bulk_document_partial');

        $partial = session('hardware_bulk_document_partial');
        $this->assertSame(['RDE950011'], $partial['succeeded']);
        $this->assertSame('RDE950012', $partial['excluded'][0]['source_id']);
        $this->assertSame(1, $this->fake->manifests);
    }

    public function test_bulk_label_download_does_not_remove_pickup_queue_classification(): void
    {
        $fulfilment = $this->pickupReadyFulfilment('RDE950013');
        $before = app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(), app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh()));

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertRedirect();

        $after = app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(), app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh()));

        $this->assertSame(HardwareFulfilmentOperationalStage::PickupManifestPending, $before->stage);
        $this->assertSame(HardwareFulfilmentOperationalStage::PickupManifestPending, $after->stage);
        $this->assertSame(HardwareDashboardQueue::Pickup, HardwareDashboardQueue::fromStage($after->stage, $after->packagePhotoRecorded));
    }

    public function test_label_pending_stays_in_ready_queue_not_completed(): void
    {
        $fulfilment = $this->awbFulfilment('RDE950014');
        $row = app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(), app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh()));

        $this->assertSame(HardwareFulfilmentOperationalStage::LabelPackingPending, $row->stage);
        $this->assertSame(HardwareDashboardQueue::Ready, $row->dashboardQueue());
        $this->assertSame(HardwareOperationsSection::InProgress, $row->section);
        $this->assertSame('Generate Label', $row->nextAction);
    }

    public function test_bulk_label_download_does_not_clear_label_pending_when_provider_fails(): void
    {
        $fulfilment = $this->awbFulfilment('RDE950015');
        $this->fake->nextLabelMode = 'rejected';

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('shipping');

        $row = app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(), app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh()));

        $this->assertSame(HardwareFulfilmentOperationalStage::LabelPackingPending, $row->stage);
        $this->assertSame(HardwareDashboardQueue::Ready, $row->dashboardQueue());
        $this->assertNull(Shipment::query()->firstOrFail()->label_url);
    }

    public function test_bulk_label_success_advances_only_through_existing_label_workflow_step(): void
    {
        $fulfilment = $this->awbFulfilment('RDE950016');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertRedirect();

        $row = app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(), app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh()));

        $this->assertSame(HardwareFulfilmentOperationalStage::PickupManifestPending, $row->stage);
        $this->assertSame(HardwareDashboardQueue::Pickup, $row->dashboardQueue());
        $this->assertSame('Request Pickup', $row->nextAction);
        $this->assertNull($fulfilment->fresh()->ready_for_pickup_at);
    }

    public function test_bulk_manifest_download_does_not_mark_ready_for_pickup(): void
    {
        $fulfilment = $this->pickupReadyFulfilment('RDE950017');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.bulk.manifest'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertRedirect();

        $this->assertNull($fulfilment->fresh()->ready_for_pickup_at);

        $row = app(HardwareFulfilmentOperationalClassifier::class)
            ->fromFulfilment($fulfilment->fresh(), app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh()));

        $this->assertSame(HardwareDashboardQueue::Pickup, $row->dashboardQueue());
        $this->assertNotSame(HardwareFulfilmentOperationalStage::Completed, $row->stage);
    }

    public function test_ready_queue_shows_bulk_labels_and_pickup_queue_shows_both_actions(): void
    {
        $labelPending = $this->awbFulfilment('RDE950018');
        $pickupReady = $this->pickupReadyFulfilment('RDE950019');

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'ready']))
            ->assertOk()
            ->assertSee('data-hardware-bulk-labels', false)
            ->assertSee('Download Labels')
            ->assertDontSee('data-hardware-bulk-manifest', false)
            ->assertDontSee('Download Manifest')
            ->assertSee('data-hardware-fulfilment-id="'.$labelPending->id.'"', false);

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'pickup']))
            ->assertOk()
            ->assertSee('data-hardware-bulk-labels', false)
            ->assertSee('data-hardware-bulk-manifest', false)
            ->assertSee('Download Labels')
            ->assertSee('Download Manifest')
            ->assertSee('data-hardware-fulfilment-id="'.$pickupReady->id.'"', false);
    }

    private function pickupReadyFulfilment(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->awbFulfilment($sourceId);
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.label.store', $fulfilment->fresh()));
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.pickup.store', $fulfilment->fresh()));

        return $fulfilment->fresh(['shipment']) ?? $fulfilment;
    }

    private function awbFulfilment(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->selectTestCourier($this->invoicedFulfilment($sourceId, 'DELHI-RETAIL'), $this->admin);
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.shipment.store', $fulfilment));
        $this->actingAs($this->admin)->post(route('inventory.hardware-fulfilments.awb.store', $fulfilment->fresh()));

        return $fulfilment->fresh(['shipment']) ?? $fulfilment;
    }

    private function invoicedFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        $fulfilment = $this->allocatedFulfilment($sourceId, $branchCode);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function allocatedFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        $fulfilment = $this->readyFulfilment($sourceId);
        $this->stockAt($branchCode, [sprintf('SN-%s-001', $sourceId)]);
        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            [sprintf('SN-%s-001', $sourceId)],
            $this->admin,
        );

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function readyFulfilment(string $sourceId): HardwareFulfilment
    {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => 946,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MFS110',
                'channel_sku' => 'RBMFS110L1',
                'notes' => 'Bulk documents test map.',
            ],
        );

        $fulfilment = $this->ingestHardware($sourceId);
        app(HardwareFulfilmentWorkflowService::class)->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): void
    {
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branch($branchCode),
            $serials,
            $this->admin,
        );
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }

    /**
     * @param  list<string>  $branchCodes
     */
    private function userWithRole(string $role, array $branchCodes): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        foreach ($branchCodes as $code) {
            InventoryUserBranch::query()->firstOrCreate([
                'user_id' => $user->id,
                'branch_id' => $this->branch($code)->id,
            ]);
        }

        return $user;
    }

    private function ingestHardware(string $sourceId): HardwareFulfilment
    {
        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
                'email' => 'buyer-'.$sourceId.'@example.test',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'shipping_address' => [
                'line1' => '12 Shipping Street',
                'city' => 'Indore',
                'state' => 'Delhi',
                'pincode' => '452001',
                'country' => 'India',
            ],
            'parcel' => [
                'weight' => 0.4,
                'length' => 20,
                'breadth' => 15,
                'height' => 10,
            ],
            'lines' => [[
                'description' => 'MFS110',
                'sku' => 'RBMFS110L1',
                'qty' => 1,
                'unit_price' => 2549,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2160.17,
                'tax_total' => 388.83,
                'line_total' => 2549.00,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 946,
            ]],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body)->assertCreated();

        return HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    }
}
