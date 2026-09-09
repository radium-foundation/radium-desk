<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentSource;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwareFulfilmentDashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_hardware_chip_matches_rendered_rows_and_excludes_pre_cutoff_hold_incidents(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $visible = $this->hardwareCase('RDE902040');
        $visible->order?->forceFill([
            'cashfree_payment_id' => 'cf_RDE902040',
            'created_at' => '2026-09-07 10:00:00',
        ])->save();

        $holdA = $this->hardwareCase('RDE255714');
        $holdA->order?->forceFill([
            'cashfree_payment_id' => 'cf_RDE255714',
            'created_at' => '2026-07-02 12:19:09',
        ])->save();

        $holdB = $this->hardwareCase('RDE313554');
        $holdB->order?->forceFill([
            'cashfree_payment_id' => 'cf_RDE313554',
            'created_at' => '2026-08-26 23:32:11',
        ])->save();

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('RDE902040')
            ->assertDontSee('RDE255714')
            ->assertDontSee('RDE313554')
            ->getContent();

        $this->assertSame(1, preg_match_all('/\sdata-hardware-select(\s|>)/', $html));
        $this->assertMatchesRegularExpression(
            '/data-dashboard-case-filter-count="hardware">\(1\)/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/data-dashboard-case-filter-count="hardware">\(1\)/',
            $this->actingAs($admin)
                ->get(route('dashboard'))
                ->assertOk()
                ->getContent(),
        );

        $exceptions = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'exceptions']))
            ->assertOk()
            ->assertDontSee('RDE902040')
            ->getContent();

        $this->assertSame(0, preg_match_all('/\sdata-hardware-select(\s|>)/', $exceptions));
        $this->assertMatchesRegularExpression(
            '/data-dashboard-case-filter-count="hardware">\(1\)/',
            $exceptions,
        );
        $this->assertStringContainsString('(1)</span>', $html);
        $this->assertStringContainsString('Ready', $html);
    }

    public function test_hardware_row_with_fulfilment_shows_existing_show_link_for_authorized_user(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902001');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('data-hardware-workspace', false)
            ->assertSee('Search hardware...')
            ->assertSee('RDE902001')
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertSee('dashboard-case-row--clickable', false)
            ->assertSee('Allocate Serial')
            ->assertSee(route('inventory.hardware-fulfilments.action-dialog', $fulfilment), false)
            ->assertSee('data-hardware-select', false)
            ->assertSee('Open selected')
            ->assertSee('MFS 110')
            ->assertDontSee('Create Shipment')
            ->assertDontSee('Create All')
            ->assertDontSee('/inventory/shipments')
            ->assertDontSee('/fulfilment/shipments');
    }

    public function test_hardware_row_without_fulfilment_does_not_invent_a_link(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->hardwareCase('RIN902002');

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('RIN902002')
            ->assertSee('Blocked')
            ->assertSee('RIN mapping required')
            ->assertSee('View')
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('data-hardware-fulfilment-link', false)
            ->getContent();

        $this->assertStringNotContainsString('/inventory/hardware-fulfilments/', $html);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_unauthorized_user_does_not_see_fulfilment_link(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902003');
        $incident->forceFill(['assigned_to_user_id' => $agent->id])->save();

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('data-hardware-fulfilment-link', false)
            ->assertDontSee(route('inventory.hardware-fulfilments.show', $fulfilment), false);
    }

    public function test_customer_360_related_menu_includes_fulfilment_link_when_resolvable(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902004');

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Open Order')
            ->assertSee('Open Fulfilment')
            ->assertSee(route('inventory.hardware-fulfilments.show', $fulfilment), false)
            ->assertSee('open-hardware-fulfilment', false)
            ->assertSee('>Hardware<', false)
            ->assertSee('id="hardware-fulfilment"', false)
            ->assertSee('View fulfilment')
            ->assertSee('view-hardware-fulfilment', false)
            ->assertDontSee('Start Hardware Fulfilment')
            ->assertSee('data-hardware-action-dialog', false)
            ->assertSee('Allocate Serial');
    }

    public function test_customer_360_hardware_section_disables_start_without_fulfilment(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->hardwareCase('RDE902007');
        $incident->order?->forceFill([
            'cashfree_payment_id' => 'cf_RDE902007',
            'created_at' => '2026-09-07 10:00:00',
        ])->save();

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('>Hardware<', false)
            ->assertSee('id="hardware-fulfilment"', false)
            ->assertSee('Review this order before fulfilment can start.')
            ->assertDontSee('Start Hardware Fulfilment')
            ->assertDontSee('Open Fulfilment');

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_customer_360_rin_is_mapping_required_without_mutating_cta(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->hardwareCase('RIN902008');

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('>Hardware<', false)
            ->assertSee('RIN')
            ->assertSee('Hardware cannot start yet.')
            ->assertSee('Verified RIN → Desk hardware mapping is required.')
            ->assertDontSee('Start Hardware Fulfilment')
            ->assertDontSee('Allocate Serial')
            ->assertDontSee('Issue Invoice')
            ->assertDontSee('Open Fulfilment');

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_customer_360_related_menu_omits_fulfilment_when_unauthorized(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902005');

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Open Order')
            ->assertDontSee('Open Fulfilment')
            ->assertDontSee(route('inventory.hardware-fulfilments.show', $fulfilment), false);
    }

    public function test_hardware_dashboard_search_and_exception_queue_are_read_only(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->hardwareCase('RDE902009');
        $rin = $this->hardwareCase('RIN902010');
        $rin->order?->forceFill([
            'cashfree_payment_id' => 'cf_RIN902010',
            'created_at' => '2026-09-07 10:00:00',
        ])->save();

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware', 'q' => 'RIN902010']))
            ->assertOk()
            ->assertSee('RIN902010')
            ->assertDontSee('RDE902009')
            ->assertDontSee('Create All');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware', 'hw_queue' => 'exceptions']))
            ->assertOk()
            ->assertSee('RIN902010')
            ->assertSee('Blocked')
            ->assertDontSee('Create All');

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_branch_scope_hides_dashboard_link_and_still_denies_show(): void
    {
        $delhi = $this->branch('DELHI-RETAIL');
        $mumbai = $this->branch('MUMBAI');
        $operator = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $operator->id,
            'branch_id' => $delhi->id,
        ]);

        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902006', $mumbai->id);

        $this->actingAs($operator)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('RDE902006')
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('data-hardware-fulfilment-link', false)
            ->assertDontSee(route('inventory.hardware-fulfilments.show', $fulfilment), false);

        $this->actingAs($operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertForbidden();
    }

    public function test_hardware_dashboard_shows_selection_bar_and_no_unsafe_bulk_actions(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->hardwareCaseWithFulfilment('RDE902010');

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('data-hardware-select-all', false)
            ->assertSee('data-hardware-select', false)
            ->assertSee('Open selected')
            ->assertDontSee('Create All')
            ->assertDontSee('Bulk Create Shipment')
            ->assertDontSee('Bulk Request Pickup')
            ->assertDontSee('Bulk Generate Manifest')
            ->getContent();

        $this->assertStringNotContainsString('data-batch-assign', $html);
    }

    public function test_hardware_action_dialog_is_the_existing_fulfilment_next_action(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        [, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902011');

        $this->actingAs($admin)
            ->get(route('inventory.hardware-fulfilments.action-dialog', $fulfilment))
            ->assertOk()
            ->assertSee('Allocate Serial')
            ->assertSee('Assign the verified physical device to this order.')
            ->assertSee('c360-correction-dialog', false)
            ->assertSee('RDE902011')
            ->assertDontSee('Label-applied package photo');
    }

    public function test_customer_360_exposes_persisted_label_and_manifest_downloads(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902021');
        $labelUrl = 'https://provider.test/labels/RDE902021.pdf';
        $manifestUrl = 'https://provider.test/manifests/RDE902021.pdf';
        $shipment = Shipment::query()->create([
            'shipment_no' => 'HW-RDE902021',
            'commerce_order_id' => $fulfilment->commerce_order_id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::AwbAssigned,
            'external_order_id' => 'ext-RDE902021',
            'external_shipment_id' => 'shp-RDE902021',
            'awb' => 'AWB902021',
            'label_url' => $labelUrl,
            'manifest_url' => $manifestUrl,
            'idempotency_key' => 'ship:RDE902021',
            'correlation_id' => (string) Str::uuid(),
        ]);
        $fulfilment->forceFill([
            'state' => HardwareFulfilmentState::AwbAssigned,
            'shipment_id' => $shipment->id,
            'awb' => 'AWB902021',
        ])->save();

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Download Label')
            ->assertSee('Download Manifest')
            ->assertSee('id="c360-hardware-label-download"', false)
            ->assertSee('id="c360-hardware-manifest-download"', false)
            ->assertSee(route('inventory.hardware-fulfilments.label.download', $fulfilment), false)
            ->assertSee(route('inventory.hardware-fulfilments.manifest.download', $fulfilment), false)
            ->assertSee('Label generated')
            ->assertSee('Manifest generated')
            ->assertDontSee($labelUrl, false)
            ->assertDontSee($manifestUrl, false);

        $this->actingAs($agent)
            ->get(route('inventory.hardware-fulfilments.label.download', $fulfilment->fresh()))
            ->assertRedirect($labelUrl);
        $this->actingAs($agent)
            ->get(route('inventory.hardware-fulfilments.manifest.download', $fulfilment->fresh()))
            ->assertRedirect($manifestUrl);

        $supportAgent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident->forceFill(['assigned_to_user_id' => $supportAgent->id])->save();
        $this->actingAs($supportAgent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Download Label')
            ->assertSee('Download Manifest')
            ->assertSee('id="c360-hardware-label-download"', false)
            ->assertSee('id="c360-hardware-manifest-download"', false)
            ->assertSee(route('inventory.hardware-fulfilments.label.download', $fulfilment), false)
            ->assertSee(route('inventory.hardware-fulfilments.manifest.download', $fulfilment), false)
            ->assertDontSee('Open Fulfilment')
            ->assertDontSee('data-hardware-action-dialog', false)
            ->assertDontSee($labelUrl, false)
            ->assertDontSee($manifestUrl, false);

        $this->actingAs($supportAgent)
            ->get(route('inventory.hardware-fulfilments.label.download', $fulfilment->fresh()))
            ->assertRedirect($labelUrl);
        $this->actingAs($supportAgent)
            ->get(route('inventory.hardware-fulfilments.manifest.download', $fulfilment->fresh()))
            ->assertRedirect($manifestUrl);
        $this->actingAs($supportAgent)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment->fresh()))
            ->assertForbidden();
        $this->actingAs($supportAgent)
            ->post(route('inventory.hardware-fulfilments.label.store', $fulfilment->fresh()))
            ->assertForbidden();
        $this->actingAs($supportAgent)
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment->fresh()))
            ->assertForbidden();

        $supportSpecialist = $this->userWithRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $this->actingAs($supportSpecialist)
            ->get(route('inventory.hardware-fulfilments.label.download', $fulfilment->fresh()))
            ->assertForbidden();

        $stranger = User::factory()->create(['is_active' => true]);
        $this->actingAs($stranger)
            ->get(route('inventory.hardware-fulfilments.label.download', $fulfilment->fresh()))
            ->assertForbidden();
    }

    /**
     * @return array{0: Incident, 1: HardwareFulfilment}
     */
    private function hardwareCaseWithFulfilment(string $orderId, ?int $branchId = null): array
    {
        $incident = $this->hardwareCase($orderId);
        $order = $incident->order;
        $this->assertNotNull($order);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$orderId,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $branchId,
            'ingested_at' => now(),
        ]);

        return [$incident, $fulfilment];
    }

    private function hardwareCase(string $orderId): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hardware case '.$orderId,
            'description' => 'Hardware dashboard case.',
            'status' => 'open',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }
}
