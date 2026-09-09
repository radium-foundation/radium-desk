<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentSource;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentOpenRecoveredHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.post_finance_journals' => false,
            'shipping.enabled' => true,
            'shipping.http_enabled' => false,
        ]);
    }

    public function test_dashboard_offers_open_fulfilment_and_the_post_opens_one_hf(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $sourceId = HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[1];
        $support = $this->supportOrder($sourceId);
        $commerce = $this->paidCommerce($support, $sourceId, 946);
        app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $commerce,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee($sourceId)
            ->assertSee('Open Fulfilment')
            ->assertSee(route('inventory.hardware-fulfilments.awaiting.action-dialog', $support), false)
            ->assertDontSee('>Review</span>', false);

        $this->actingAs($admin)
            ->get(route('inventory.hardware-fulfilments.awaiting.action-dialog', $support))
            ->assertOk()
            ->assertSee('Open Fulfilment');

        $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.awaiting.open', $support))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(1, CommerceOrder::query()->count());
        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame($sourceId, $fulfilment->source_id);
        $this->assertContains(
            $fulfilment->state,
            [HardwareFulfilmentState::Ingested, HardwareFulfilmentState::ReadyForFulfilment],
        );

        $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.awaiting.open', $support))
            ->assertOk();

        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_unauthorized_frozen_blocked_hold_and_awaiting_handoff_cannot_open(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $frozen = $this->supportOrder(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0]);
        $this->paidCommerce($frozen, HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], 946);

        $blocked = $this->supportOrder(HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0]);
        $this->paidCommerce($blocked, HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0], 946);

        $hold = $this->supportOrder(HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0]);
        $awaiting = $this->supportOrder('RDE971050');

        $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.awaiting.open', $frozen))
            ->assertStatus(422);
        $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.awaiting.open', $blocked))
            ->assertStatus(422);
        $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.awaiting.open', $hold))
            ->assertStatus(422);
        $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.awaiting.open', $awaiting))
            ->assertStatus(422);

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_customer_360_stays_available_on_recovered_rows(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $sourceId = HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[2];
        $support = $this->supportOrder($sourceId);
        $commerce = $this->paidCommerce($support, $sourceId, 930);
        app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $commerce,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_RECOVERED,
            'test',
        );

        $incident = Incident::query()->create([
            'order_id' => $support->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Recovered hardware case',
            'description' => 'Customer 360 remains available.',
            'status' => 'open',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.orders.customer-360', $support))
            ->assertOk()
            ->assertSee($sourceId);

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee($sourceId)
            ->assertSee('Open Fulfilment');
    }

    private function supportOrder(string $orderId): Order
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'Recovered hardware',
            'status' => 'active',
            'created_by' => User::factory()->create(['is_active' => true])->id,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'payment_amount' => 1000,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        return $order->fresh();
    }

    private function paidCommerce(Order $support, string $sourceId, int $modelId): CommerceOrder
    {
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'currency' => 'INR',
            'received_at' => now(),
            'ordered_at' => '2026-09-07 10:00:00',
            'paid_at' => '2026-09-07 10:05:00',
            'support_order_id' => $support->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => (string) $modelId,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'model_id' => $modelId,
            'description' => 'Hardware '.$modelId,
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 1000,
            'gst_percentage' => 18,
            'taxable_value' => 847.46,
            'tax_total' => 152.54,
            'line_total' => 1000,
        ]);

        return $commerce->fresh(['items']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
