<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderFinanceHubIssueTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_eligible_commerce_order_shows_an_available_issue_action(): void
    {
        $order = $this->commerceOrder('ELIGIBLE');

        $this->assertTrue(app(StatutoryMintEligibility::class)->evaluateOrder($order)->eligible);

        $html = $this->actingAs($this->actor)
            ->get(route('finance.invoices.commerce-orders.show', $order))
            ->assertOk()
            ->assertSee('Ready to issue', false)
            ->assertSee('Issue statutory invoice', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*\bdisabled\b[^>]*>Issue statutory invoice<\/button>/',
            $html,
        );
    }

    public function test_ineligible_commerce_order_disables_the_issue_action(): void
    {
        $order = $this->commerceOrder('INELIGIBLE', placeOfSupply: '');

        $eligibility = app(StatutoryMintEligibility::class)->evaluateOrder($order);
        $this->assertFalse($eligibility->eligible);
        $this->assertContains('Place of supply is missing.', $eligibility->errors);

        $html = $this->actingAs($this->actor)
            ->get(route('finance.invoices.commerce-orders.show', $order))
            ->assertOk()
            ->assertSee('Place of supply is missing.', false)
            ->assertSee('Issue statutory invoice', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*\bdisabled\b[^>]*>Issue statutory invoice<\/button>/',
            $html,
        );
    }

    public function test_server_still_refuses_to_mint_an_ineligible_commerce_order(): void
    {
        $order = $this->commerceOrder('POST-BLOCK', placeOfSupply: '');

        $this->actingAs($this->actor)
            ->from(route('finance.invoices.commerce-orders.show', $order))
            ->post(route('finance.invoices.commerce-orders.issue', $order))
            ->assertRedirect(route('finance.invoices.commerce-orders.show', $order))
            ->assertSessionHasErrors('eligibility');

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertNull($order->fresh()->statutory_invoice_id);
    }

    private function commerceOrder(string $sourceId, string $placeOfSupply = 'Delhi'): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_net:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'buyer_gstin' => null,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $placeOfSupply === '' ? null : $placeOfSupply,
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-01 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }
}
