<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\ShipmentStatus;
use App\Enums\ShiprocketTrackNormalized;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwareDashboardNeedsActionQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
    }

    public function test_default_hardware_view_is_needs_action_and_skips_ingested_inspect(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $this->fulfilment('RDE980001', HardwareFulfilmentState::Ingested);
        $this->fulfilment('RDE980002', HardwareFulfilmentState::Ingested);
        $awaiting = $this->fulfilment('RDE980003', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE980004', HardwareFulfilmentState::InvoiceIssued, serial: true, invoice: true);

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('Needs Action')
            ->assertSee('Product Mapping Required')
            ->assertSee('Awaiting Serial')
            ->assertSee('AWB Pending')
            ->assertSee('Package Photo Pending')
            ->assertSee('Shipping')
            ->assertSee('Completed')
            ->assertSee('All')
            ->assertSee('RDE980003')
            ->assertDontSee('RDE980001')
            ->assertDontSee('Out for Delivery')
            ->getContent();

        $this->assertStringContainsString('data-hardware-filter="needs_action"', $html);
        $this->assertStringContainsString('data-live-hardware-url="', $html);
        $this->assertStringContainsString('Allocate Serial', $html);
        $this->assertSame(1, preg_match_all('/\sdata-hardware-select(\s|>)/', $html));
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="awaiting_serial">\(1\)/', $html);

        $inspected = (int) (preg_match('/data-hardware-inspected-count="(\d+)"/', $html, $match) ? $match[1] : -1);
        $this->assertLessThan(4, $inspected);
        $this->assertGreaterThan(0, $inspected);

        $queue = app(HardwareFulfilmentWorkQueue::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $needsAction = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);
        $this->assertSame(1, $needsAction['unfiltered_total']);
        $this->assertSame(1, $queue->lastInspectedFulfilmentCount);
        $this->assertSame(1, $needsAction['filter_counts']['awaiting_serial']);
        $this->assertSame(1, $needsAction['filter_counts']['needs_action']);

        $all = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::All);
        $this->assertSame(4, $all['unfiltered_total']);
        $this->assertSame(4, $queue->lastInspectedFulfilmentCount);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'all']))
            ->assertOk()
            ->assertSee('RDE980001')
            ->assertSee('RDE980003')
            ->assertSee('RDE980004');

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_scope' => 'shipped']))
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee('Delivered');

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'shipping']))
            ->assertOk()
            ->assertSee('Out for Pickup')
            ->assertSee('Ready for Pickup')
            ->assertSee('In Transit')
            ->assertSee('Picked Up')
            ->assertDontSee('Out for Delivery');

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.hardware', [
                'ids' => [$awaiting->id],
                'hw_filter' => 'needs_action',
            ]))
            ->assertOk()
            ->assertJsonPath('rows.0.fulfilment_id', $awaiting->id)
            ->assertJsonPath('rows.0.filter', 'awaiting_serial');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $awaiting->fresh()->state);
    }

    public function test_needs_action_paginates_without_rendering_the_full_queue(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 45; $i++) {
            $this->fulfilment(sprintf('RDE981%03d', $i), HardwareFulfilmentState::ReadyForFulfilment);
        }

        $first = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('Page 1 of 2 (45)')
            ->getContent();
        $this->assertSame(40, preg_match_all('/\sdata-hardware-select(\s|>)/', $first));
        $this->assertSame(40, $this->inspectedCount($first));

        $second = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_page' => 2]))
            ->assertOk()
            ->getContent();
        $this->assertSame(5, preg_match_all('/\sdata-hardware-select(\s|>)/', $second));
        $this->assertSame(5, $this->inspectedCount($second));
    }

    public function test_sql_counts_match_four_needs_action_queues_and_exclude_ingested_and_shipped(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $this->fulfilment('RDE982001', HardwareFulfilmentState::Ingested);
        $this->fulfilment('RDE982002', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);
        $awaiting = $this->fulfilment('RDE982003', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE982004', HardwareFulfilmentState::ShipmentCreated, serial: true, invoice: true, bound: true);
        $this->fulfilment(
            'RDE982005',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB982005',
            label: true,
            pickup: true,
            manifest: true,
        );
        $this->fulfilment(
            'RDE982006',
            HardwareFulfilmentState::Shipped,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB982006',
            label: true,
            pickup: true,
            manifest: true,
        );

        $queue = app(HardwareFulfilmentWorkQueue::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $dashboard = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);

        $this->assertSame(1, $dashboard['filter_counts']['mapping_required']);
        $this->assertSame(1, $dashboard['filter_counts']['awaiting_serial']);
        $this->assertSame(1, $dashboard['filter_counts']['awb_pending']);
        $this->assertSame(1, $dashboard['filter_counts']['package_photo_pending']);
        $this->assertSame(4, $dashboard['filter_counts']['needs_action']);
        $this->assertSame(4, $dashboard['unfiltered_total']);
        $this->assertSame(4, $dashboard['rows']->count());
        $this->assertSame(4, $queue->lastInspectedFulfilmentCount);
        $this->assertSame(1, $dashboard['filter_counts']['completed']);

        $mapping = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::MappingRequired);
        $this->assertSame(['RDE982002'], $mapping['rows']->pluck('sourceId')->all());
        $this->assertSame(1, $queue->lastInspectedFulfilmentCount);

        $serial = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::AwaitingSerial);
        $this->assertSame(['RDE982003'], $serial['rows']->pluck('sourceId')->all());

        $awb = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::AwbPending);
        $this->assertSame(['RDE982004'], $awb['rows']->pluck('sourceId')->all());

        $photo = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::PackagePhotoPending);
        $this->assertSame(['RDE982005'], $photo['rows']->pluck('sourceId')->all());

        $search = $queue->dashboard($from, $to, 'RDE982003', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);
        $this->assertSame(1, $search['filter_counts']['awaiting_serial']);
        $this->assertSame(0, $search['filter_counts']['mapping_required']);
        $this->assertSame(0, $search['filter_counts']['awb_pending']);
        $this->assertSame(0, $search['filter_counts']['package_photo_pending']);
        $this->assertSame(['RDE982003'], $search['rows']->pluck('sourceId')->all());

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'mapping_required']))
            ->assertOk()
            ->assertSee('RDE982002')
            ->assertDontSee('RDE982003')
            ->assertDontSee('RDE982001');

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $awaiting->fresh()->state);
    }

    public function test_sql_counts_do_not_inspect_or_load_all_fulfilments(): void
    {
        $this->seedCatalog();
        $this->fulfilment('RDE983001', HardwareFulfilmentState::Ingested);
        $this->fulfilment('RDE983002', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE983003', HardwareFulfilmentState::ShipmentCreated, serial: true, invoice: true, bound: true);
        $this->fulfilment('RDE983004', HardwareFulfilmentState::Shipped, serial: true, invoice: true, bound: true, awb: 'AWB983004');

        $this->mock(HardwareShipmentEligibility::class, function ($mock): void {
            $mock->shouldNotReceive('inspect');
        });

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $counts = app(HardwareNeedsActionSqlQuery::class)->filterCounts($from, $to, '');

        $this->assertSame(0, $counts['mapping_required']);
        $this->assertSame(1, $counts['awaiting_serial']);
        $this->assertSame(1, $counts['awb_pending']);
        $this->assertSame(0, $counts['package_photo_pending']);
        $this->assertSame(2, $counts['needs_action']);
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(0, $counts['package_photo_pending']);
    }

    public function test_date_and_search_filters_apply_to_sql_counts_and_selected_queue_is_bounded(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $this->fulfilment('RDE984001', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE984002', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);
        $rin = Order::query()->create([
            'order_id' => 'RIN984003',
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'RIN buyer',
            'created_by' => User::factory()->create(['is_active' => true])->id,
        ]);
        $rin->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();
        $oldRin = Order::query()->create([
            'order_id' => 'RIN984004',
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Old RIN',
            'created_by' => User::factory()->create(['is_active' => true])->id,
        ]);
        $oldRin->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();

        $from = Carbon::parse('2026-09-06 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE)->startOfDay();
        $to = Carbon::parse('2026-09-08 00:00:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE)->endOfDay();
        $queue = app(HardwareFulfilmentWorkQueue::class);

        $counts = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);
        $this->assertSame(1, $counts['filter_counts']['awaiting_serial']);
        $this->assertSame(2, $counts['filter_counts']['mapping_required']);
        $this->assertSame(3, $counts['filter_counts']['needs_action']);
        $this->assertSame(3, $counts['unfiltered_total']);
        $this->assertSame(2, $queue->lastInspectedFulfilmentCount);

        $search = $queue->dashboard($from, $to, 'RDE984001', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::NeedsAction);
        $this->assertSame(1, $search['filter_counts']['awaiting_serial']);
        $this->assertSame(0, $search['filter_counts']['mapping_required']);
        $this->assertSame(['RDE984001'], $search['rows']->pluck('sourceId')->all());

        $mapping = $queue->dashboard($from, $to, '', HardwareWorkspaceScope::Active, HardwareWorkspaceFilter::MappingRequired);
        $this->assertSame(2, $mapping['unfiltered_total']);
        $this->assertNotContains('RDE984001', $mapping['rows']->pluck('sourceId')->all());
        $this->assertContains('RDE984002', $mapping['rows']->pluck('sourceId')->all());
        $this->assertContains('RIN984003', $mapping['rows']->pluck('sourceId')->all());
        $this->assertNotContains('RIN984004', $mapping['rows']->pluck('sourceId')->all());

        $this->actingAs($admin)
            ->get(route('dashboard', [
                'queue' => 'hardware',
                'hw_filter' => 'awaiting_serial',
                'from' => '2026-09-06',
                'to' => '2026-09-08',
            ]))
            ->assertOk()
            ->assertSee('RDE984001')
            ->assertDontSee('RDE984002')
            ->assertDontSee('RIN984003');
    }

    public function test_legacy_exceptions_queue_still_filters_rows(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();
        $this->fulfilment('RDE985001', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_queue' => 'exceptions']))
            ->assertOk()
            ->assertSee('RDE985001')
            ->getContent();
        $this->assertStringContainsString('data-hardware-filter="exceptions"', $html);
        $this->assertSame(1, preg_match_all('/\sdata-hardware-select(\s|>)/', $html));
    }

    public function test_package_photo_pending_needs_action_excludes_advanced_provider_tracking(): void
    {
        $this->seedCatalog();

        $preShipping = $this->fulfilment(
            'RDE988000',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB988000',
            label: true,
            pickup: true,
            manifest: true,
        );
        $mapping = $this->fulfilment('RDE988001', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);
        $awaitingSerial = $this->fulfilment('RDE988002', HardwareFulfilmentState::ReadyForFulfilment);
        $awbPending = $this->fulfilment('RDE988003', HardwareFulfilmentState::ShipmentCreated, serial: true, invoice: true, bound: true);
        $outForPickup = $this->packagePhotoFulfilmentWithTrack('RDE988004', ShiprocketTrackNormalized::OutForPickup);
        $pickedUp = $this->packagePhotoFulfilmentWithTrack('RDE988005', ShiprocketTrackNormalized::PickedUp);
        $inTransit = $this->packagePhotoFulfilmentWithTrack('RDE988006', ShiprocketTrackNormalized::InTransit);
        $delivered = $this->packagePhotoFulfilmentWithTrack('RDE988007', ShiprocketTrackNormalized::Delivered);
        $unknownTrack = $this->packagePhotoFulfilmentWithTrack('RDE988008', ShiprocketTrackNormalized::Unknown);
        $nullTrack = $this->fulfilment(
            'RDE988009',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB988009',
            label: true,
            pickup: true,
            manifest: true,
        );
        $shippingControl = $this->fulfilment(
            'RDE988010',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB988010',
            label: true,
            pickup: true,
            manifest: true,
            evidence: true,
            track: ShiprocketTrackNormalized::PickedUp->value,
        );
        $completed = $this->fulfilment(
            'RDE988011',
            HardwareFulfilmentState::Shipped,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB988011',
            label: true,
            pickup: true,
            manifest: true,
            evidence: true,
            track: ShiprocketTrackNormalized::Delivered->value,
        );

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $sql = app(HardwareNeedsActionSqlQuery::class);
        $counts = $sql->filterCounts($from, $to, '');
        $needsActionIds = $sql->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::NeedsAction,
            $from,
            $to,
            '',
            1,
            50,
        )['fulfilment_ids'];

        $this->assertContains($preShipping->id, $needsActionIds);
        $this->assertContains($unknownTrack->id, $needsActionIds);
        $this->assertContains($nullTrack->id, $needsActionIds);
        $this->assertNotContains($outForPickup->id, $needsActionIds);
        $this->assertNotContains($pickedUp->id, $needsActionIds);
        $this->assertNotContains($inTransit->id, $needsActionIds);
        $this->assertNotContains($delivered->id, $needsActionIds);
        $this->assertNotContains($shippingControl->id, $needsActionIds);
        $this->assertNotContains($completed->id, $needsActionIds);

        $this->assertSame(3, $counts['package_photo_pending']);
        $this->assertSame(6, $counts['needs_action']);
        $this->assertSame(1, $counts['mapping_required']);
        $this->assertSame(1, $counts['awaiting_serial']);
        $this->assertSame(1, $counts['awb_pending']);
        $this->assertSame(1, $counts['completed']);
        $this->assertGreaterThanOrEqual(1, $counts['picked_up']);
        $this->assertGreaterThanOrEqual(1, $counts['shipping']);

        $mappingPage = $sql->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::MappingRequired,
            $from,
            $to,
            '',
            1,
            50,
        )['fulfilment_ids'];
        $this->assertContains($mapping->id, $mappingPage);

        $serialPage = $sql->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::AwaitingSerial,
            $from,
            $to,
            '',
            1,
            50,
        )['fulfilment_ids'];
        $this->assertContains($awaitingSerial->id, $serialPage);

        $awbPage = $sql->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::AwbPending,
            $from,
            $to,
            '',
            1,
            50,
        )['fulfilment_ids'];
        $this->assertContains($awbPending->id, $awbPage);

        $pickedUpPage = $sql->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::PickedUp,
            $from,
            $to,
            '',
            1,
            50,
        )['fulfilment_ids'];
        $this->assertContains($pickedUp->id, $pickedUpPage);
        $this->assertContains($shippingControl->id, $pickedUpPage);
        $this->assertNotContains($preShipping->id, $pickedUpPage);

        $completedPage = $sql->page(
            HardwareWorkspaceScope::Shipped,
            HardwareWorkspaceFilter::Completed,
            $from,
            $to,
            '',
            1,
            50,
        )['fulfilment_ids'];
        $this->assertContains($completed->id, $completedPage);
    }

    public function test_shipping_subfilters_use_provider_track_sql_without_inspecting_delivered(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        $this->fulfilment(
            'RDE987001',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB987001',
            label: true,
            pickup: true,
            manifest: true,
            evidence: true,
            track: 'out_for_pickup',
        );
        $this->fulfilment(
            'RDE987002',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB987002',
            label: true,
            pickup: true,
            manifest: true,
            evidence: true,
            track: 'delivered',
        );
        $this->fulfilment(
            'RDE987003',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB987003',
            label: true,
            pickup: true,
            manifest: true,
            evidence: true,
            track: 'in_transit',
        );

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware', 'hw_filter' => 'out_for_pickup']))
            ->assertOk()
            ->assertSee('RDE987001')
            ->assertDontSee('RDE987002')
            ->assertDontSee('RDE987003')
            ->assertDontSee('Out for Delivery')
            ->getContent();

        $this->assertMatchesRegularExpression('/data-hardware-filter-count="out_for_pickup">\(1\)/', $html);
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="in_transit">\(1\)/', $html);
        $this->assertSame(1, preg_match_all('/\sdata-hardware-select(\s|>)/', $html));
        $this->assertSame(1, $this->inspectedCount($html));

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $counts = app(HardwareNeedsActionSqlQuery::class)->filterCounts($from, $to);
        $this->assertSame(1, $counts['out_for_pickup']);
        $this->assertSame(1, $counts['in_transit']);
        $this->assertSame(0, $counts['picked_up']);
        $this->assertSame(2, $counts['shipping']);
    }

    public function test_default_hardware_ssr_does_not_materialize_ingested_majority(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();

        for ($i = 1; $i <= 72; $i++) {
            $this->fulfilment(sprintf('RDE986%03d', $i), HardwareFulfilmentState::Ingested);
        }
        $this->fulfilment('RDE986073', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE986074', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);
        $this->fulfilment('RDE986075', HardwareFulfilmentState::ShipmentCreated, serial: true, invoice: true, bound: true);
        $this->fulfilment(
            'RDE986076',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB986076',
            label: true,
            pickup: true,
            manifest: true,
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->assertSee('RDE986073')
            ->assertSee('RDE986074')
            ->assertSee('RDE986075')
            ->assertSee('RDE986076')
            ->assertDontSee('RDE986001')
            ->getContent();
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $log = DB::getQueryLog();
        DB::flushQueryLog();
        DB::disableQueryLog();

        $sqlMs = array_sum(array_map(static fn (array $row): float => (float) ($row['time'] ?? 0), $log));
        $this->assertSame(4, preg_match_all('/\sdata-hardware-select(\s|>)/', $html));
        $this->assertSame(4, $this->inspectedCount($html));
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="mapping_required">\(1\)/', $html);
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="awaiting_serial">\(1\)/', $html);
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="awb_pending">\(1\)/', $html);
        $this->assertMatchesRegularExpression('/data-hardware-filter-count="package_photo_pending">\(1\)/', $html);
        $this->assertLessThan(120_000, strlen($html));
        $this->assertLessThan(120, count($log));
        $this->assertGreaterThanOrEqual(0, $sqlMs);
        $this->assertLessThan(2500, $elapsedMs);
    }

    private function seedCatalog(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }

    private function inspectedCount(string $html): int
    {
        return (int) (preg_match('/data-hardware-inspected-count="(\d+)"/', $html, $match) ? $match[1] : -1);
    }

    private function packagePhotoFulfilmentWithTrack(string $sourceId, ShiprocketTrackNormalized $track): HardwareFulfilment
    {
        return $this->fulfilment(
            $sourceId,
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB-'.$sourceId,
            label: true,
            pickup: true,
            manifest: true,
            track: $track->value,
        );
    }

    private function fulfilment(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $serial = false,
        bool $invoice = false,
        bool $mapped = true,
        bool $bound = false,
        ?string $awb = null,
        bool $label = false,
        bool $pickup = false,
        bool $manifest = false,
        bool $evidence = false,
        ?string $track = null,
    ): HardwareFulfilment {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Buyer '.$sourceId,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        $invoiceModel = null;
        if ($invoice) {
            $invoiceModel = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-'.$sourceId,
                'document_type' => 'tax_invoice',
                'status' => 'issued',
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);
        }

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
            'customer_name' => 'Buyer '.$sourceId,
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel?->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => $mapped ? 946 : 99999,
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
            'statutory_invoice_id' => $invoiceModel?->id,
            'ingested_at' => now(),
        ]);

        if ($serial) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-'.$sourceId,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        if ($bound || $awb !== null || $label || $pickup || $manifest) {
            $shipment = Shipment::query()->create([
                'shipment_no' => 'HW-'.$sourceId,
                'commerce_order_id' => $commerce->id,
                'hardware_fulfilment_id' => $fulfilment->id,
                'provider' => 'shiprocket',
                'status' => $awb !== null ? ShipmentStatus::AwbAssigned : ShipmentStatus::Created,
                'external_order_id' => $bound ? 'ext-'.$sourceId : null,
                'external_shipment_id' => $bound ? 'shp-'.$sourceId : null,
                'awb' => $awb,
                'label_url' => $label ? '/labels/'.$sourceId.'.pdf' : null,
                'pickup_requested_at' => $pickup ? now() : null,
                'manifest_id' => $manifest ? 'man-'.$sourceId : null,
                'provider_track_normalized' => $track,
                'provider_track_status' => $track,
                'idempotency_key' => 'ship:'.$sourceId,
                'correlation_id' => (string) Str::uuid(),
            ]);
            $fulfilment->forceFill([
                'shipment_id' => $shipment->id,
                'awb' => $awb,
            ])->save();
        }

        if ($evidence) {
            HardwareFulfilmentPackageEvidence::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied,
                'disk' => 'local',
                'path' => 'evidence/'.$sourceId.'.jpg',
                'original_filename' => $sourceId.'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 12,
                'uploaded_by_user_id' => $creator->id,
                'uploaded_at' => now(),
            ]);
        }

        return $fulfilment->fresh() ?? $fulfilment;
    }
}
