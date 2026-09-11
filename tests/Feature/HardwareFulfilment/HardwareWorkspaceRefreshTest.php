<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentSource;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareWorkspaceRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_hardware_workspace_refresh_returns_authoritative_html_and_counts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RDE902040',
            'customer_name' => 'RAMESH KUMAR',
            'cashfree_payment_id' => 'cf_RDE902040',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
        $order->forceFill([
            'created_at' => '2026-09-10 19:16:00',
            'updated_at' => '2026-09-10 19:22:00',
        ])->save();

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hardware case RDE902040',
            'description' => 'Hardware dashboard case.',
            'status' => 'open',
            'created_by' => $creator->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware-workspace', ['workspace' => 'hardware']));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'workspace_html',
                'scope_counts' => ['active', 'shipped'],
                'filter_counts' => ['all', 'ready', 'exceptions', 'pickup', 'scheduled'],
                'hardware_chip_count',
            ]);

        $html = (string) $response->json('workspace_html');
        $this->assertStringContainsString('data-hardware-workspace', $html);
        $this->assertStringContainsString('RDE902040', $html);
        $this->assertStringContainsString('dashboard-timeline-cell', $html);
        $this->assertStringContainsString('10 Sep 07:16 PM L 07:22 PM', $html);
        $this->assertStringNotContainsString('dashboard-hardware-datetime__label', $html);
        $this->assertStringNotContainsString('>ORDER<', $html);
        $this->assertStringNotContainsString('>Last action<', $html);
        $this->assertStringContainsString('datetime=', $html);
    }

    public function test_b2b_customer_renders_superscript_in_workspace_html(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RDE902041',
            'customer_name' => 'RAMESH KUMAR',
            'cashfree_payment_id' => 'cf_RDE902041',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hardware case RDE902041',
            'description' => 'Hardware dashboard case.',
            'status' => 'open',
            'created_by' => $creator->id,
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-RDE902041',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902041',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE902041',
            'payload_hash' => hash('sha256', 'RDE902041'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
            'buyer_gstin' => '29AABCU9603R1ZM',
        ]);

        $html = (string) $this->actingAs($admin)
            ->getJson(route('dashboard.hardware-workspace', ['workspace' => 'hardware']))
            ->json('workspace_html');

        $this->assertStringContainsString('dashboard-hardware-b2b', $html);
        $this->assertStringContainsString('B2B customer', $html);
        $this->assertStringContainsString('RAMESH KUMAR', $html);
    }

    public function test_shipped_scope_excludes_active_work_from_refresh_payload(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RDE902042',
            'customer_name' => 'Shipped Buyer',
            'cashfree_payment_id' => 'cf_RDE902042',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hardware case RDE902042',
            'description' => 'Hardware dashboard case.',
            'status' => 'open',
            'created_by' => $creator->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RDE902042',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902042',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE902042',
            'payload_hash' => hash('sha256', 'RDE902042'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902042',
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::Shipped,
            'support_order_id' => $order->id,
            'ingested_at' => now(),
        ]);

        $active = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware-workspace', [
                'workspace' => 'hardware',
                'hw_scope' => 'active',
            ]));
        $active->assertOk()
            ->assertJsonPath('scope_counts.active', 0)
            ->assertJsonPath('scope_counts.shipped', 1);
        $this->assertStringNotContainsString('RDE902042', (string) $active->json('workspace_html'));

        $shipped = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware-workspace', [
                'workspace' => 'hardware',
                'hw_scope' => 'shipped',
            ]));
        $shipped->assertOk()
            ->assertJsonPath('scope_counts.shipped', 1);
        $this->assertStringContainsString('RDE902042', (string) $shipped->json('workspace_html'));
    }

    public function test_hardware_workspace_refresh_requires_hardware_access(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('dashboard.hardware-workspace', ['workspace' => 'hardware']))
            ->assertForbidden();
    }
}
