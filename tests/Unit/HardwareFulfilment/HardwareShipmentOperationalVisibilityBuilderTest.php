<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\ShipmentStatus;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Services\HardwareFulfilment\HardwareShipmentOperationalVisibilityBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareShipmentOperationalVisibilityBuilderTest extends TestCase
{
    use RefreshDatabase;

    private HardwareShipmentOperationalVisibilityBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new HardwareShipmentOperationalVisibilityBuilder;
    }

    public function test_awb_without_label_states_label_not_generated_not_pickup(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP9001', 'shipment_no' => 'RBP9001']);
        $shipment = new Shipment([
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => 'AWB123',
            'external_shipment_id' => '579',
            'pickup_requested_at' => null,
        ]);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'awb' => 'AWB123',
            'labelUrl' => null,
            'pickupStatus' => 'Not requested',
            'status' => 'AWB assigned',
        ]);

        $panel = $this->builder->build($shipment, $ready, $fulfilment);

        $this->assertSame('AWB assigned — label not generated', $panel->lifecycleHeadline);
        $this->assertStringContainsString('label has not been generated', $panel->lifecycleDetail);
        $this->assertSame('Not requested', $panel->deskPickupEvidence);
        $this->assertFalse($panel->blocked);
    }

    public function test_label_without_desk_pickup_is_explicit(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP9002']);
        $shipment = new Shipment([
            'awb' => 'AWB124',
            'label_url' => 'https://example.test/label.pdf',
            'pickup_requested_at' => null,
        ]);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'awb' => 'AWB124',
            'labelUrl' => 'https://example.test/label.pdf',
            'labelStatus' => 'Available',
            'pickupStatus' => 'Not requested',
        ]);

        $panel = $this->builder->build($shipment, $ready, $fulfilment);

        $this->assertSame('Label generated — pickup not requested', $panel->lifecycleHeadline);
        $this->assertStringContainsString('has not called Request Pickup', $panel->lifecycleDetail);
    }

    public function test_pickup_error_blocks_and_distinguishes_desk_pickup(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP2281', 'shipment_no' => 'HW-RBP2281']);
        $shipment = new Shipment([
            'awb' => 'SF4531928884SPF',
            'provider_track_status' => 'Pickup Error',
            'pickup_requested_at' => null,
        ]);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'awb' => 'SF4531928884SPF',
            'providerTrackStatus' => 'Pickup Error',
            'pickupStatus' => 'Not requested',
            'courier' => 'Instant pickup - Shadowfax',
        ]);

        $panel = $this->builder->build($shipment, $ready, $fulfilment);

        $this->assertTrue($panel->blocked);
        $this->assertSame('Pickup error (Shiprocket / courier)', $panel->lifecycleHeadline);
        $this->assertStringContainsString('Desk pickup was never requested', $panel->lifecycleDetail);
        $this->assertTrue($panel->merchantReferenceIsLegacyHw);
        $this->assertSame('HW-RBP2281', $panel->shiprocketMerchantReference);
    }

    public function test_stale_tracking_when_provider_tracked_at_is_old(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP9003']);
        $shipment = new Shipment([
            'awb' => 'AWB125',
            'provider_tracked_at' => Carbon::parse('2026-11-15 10:00:00'),
            'provider_track_status' => 'In Transit',
            'provider_track_normalized' => 'in_transit',
        ]);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'awb' => 'AWB125',
            'labelUrl' => 'https://example.test/l.pdf',
            'pickupStatus' => 'Requested',
            'providerTrackStatus' => 'In Transit',
            'providerTrackNormalized' => 'in_transit',
        ]);

        $panel = $this->builder->build($shipment, $ready, $fulfilment);

        $this->assertSame('stale', $panel->trackFreshness);
        Carbon::setTestNow();
    }

    public function test_api_audit_rows_redact_disallowed_payload_keys(): void
    {
        $fulfilment = new HardwareFulfilment(['source_id' => 'RBP9004']);
        $shipment = Shipment::query()->make([
            'status' => ShipmentStatus::Created,
            'awb' => 'AWB126',
        ]);
        $shipment->setRelation('events', collect([
            new ShipmentEvent([
                'source' => 'shiprocket_api',
                'activity' => 'shiprocket_api:generate_label',
                'payload' => [
                    'schema' => 'shiprocket_api_audit_v1',
                    'operation' => 'generate_label',
                    'outcome' => 'partial',
                    'http_status' => 200,
                    'label_created' => 0,
                    'provider_message' => 'Shipment is in pickup error state',
                    'billing_email' => 'secret@example.test',
                ],
                'created_at' => now(),
            ]),
        ]));
        $ready = $this->readiness(['alreadyCreated' => true, 'awb' => 'AWB126']);

        $panel = $this->builder->build($shipment, $ready, $fulfilment);

        $this->assertTrue($panel->partialSuccess);
        $this->assertCount(1, $panel->apiAuditRows);
        $this->assertStringNotContainsString('secret@example.test', $panel->apiAuditRows[0]->detailLine);
        $this->assertStringContainsString('partial', $panel->apiAuditRows[0]->detailLine);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function readiness(array $overrides = []): HardwareShipmentReadiness
    {
        return new HardwareShipmentReadiness(
            canCreate: false,
            blockers: [],
            status: $overrides['status'] ?? 'Created',
            pickupBranch: 'DELHI',
            pickupLocation: 'RADDELHI',
            shipTo: 'Indore',
            parcel: '1 kg',
            invoice: 'INV-1',
            serials: [],
            order: 'RBP900',
            product: 'MFS110',
            alreadyCreated: $overrides['alreadyCreated'] ?? false,
            awb: $overrides['awb'] ?? null,
            labelUrl: $overrides['labelUrl'] ?? null,
            labelStatus: $overrides['labelStatus'] ?? 'Not generated',
            pickupStatus: $overrides['pickupStatus'] ?? 'Not requested',
            providerTrackStatus: $overrides['providerTrackStatus'] ?? null,
            providerTrackNormalized: $overrides['providerTrackNormalized'] ?? null,
            courier: $overrides['courier'] ?? null,
            providerShipmentId: $overrides['providerShipmentId'] ?? '579',
        );
    }
}
