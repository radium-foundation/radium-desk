<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HardwareFulfilmentInvoiceHttpTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentWorkflowService $workflow;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->operator = User::factory()->create(['is_active' => true]);
        $this->operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    public function test_show_get_does_not_issue_invoice(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901801');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertOk()
            ->assertSee('Issue Hardware Invoice')
            ->assertSee('id="hardware-invoice"', false)
            ->assertSee('Current step');

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_post_issues_invoice_once_and_is_idempotent(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901802');

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $number = StatutoryInvoice::query()->value('invoice_number');

        $this->actingAs($this->operator)
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment->fresh()))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment));

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame($number, StatutoryInvoice::query()->value('invoice_number'));
    }

    public function test_frozen_order_cannot_issue_via_http(): void
    {
        $sourceId = HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0];
        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => CommerceOrder::query()->create([
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
                'received_at' => now(),
            ])->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'state' => HardwareFulfilmentState::SerialsAllocated,
            'ingested_at' => now(),
        ]);

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertRedirect(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertSessionHasErrors();

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_get_invoice_route_does_not_exist_and_does_not_mint(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901804');

        $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertMethodNotAllowed();

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_unallocated_fulfilment_cannot_issue_via_http(): void
    {
        $fulfilment = $this->ingestHardware('RDE901803');
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        $this->actingAs($this->operator)
            ->from(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_dashboard_json_post_issues_invoice_once_and_is_idempotent(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901805');
        $other = $this->prepareIssuable('RDE901806');

        $first = $this->actingAs($this->operator)
            ->withHeaders($this->dashboardAjaxHeaders())
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment));

        $first->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'Hardware invoice '.StatutoryInvoice::query()->value('invoice_number').' issued.');

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $number = StatutoryInvoice::query()->value('invoice_number');
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $other->fresh()->state);
        $this->assertNull($other->fresh()->statutory_invoice_id);

        $this->actingAs($this->operator)
            ->withHeaders($this->dashboardAjaxHeaders())
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment->fresh()))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame($number, StatutoryInvoice::query()->value('invoice_number'));
        $this->assertNull($other->fresh()->statutory_invoice_id);
    }

    public function test_dashboard_json_validation_failure_is_json_not_a_silent_redirect(): void
    {
        $fulfilment = $this->ingestHardware('RDE901807');
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        $this->actingAs($this->operator)
            ->withHeaders($this->dashboardAjaxHeaders())
            ->from(route('dashboard'))
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonFragment(['Hardware invoice issuance requires SERIALS_ALLOCATED. READY_FOR_FULFILMENT cannot skip serials.']);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
    }

    public function test_dashboard_json_post_issues_when_gst_rate_is_absent_but_amounts_reconcile(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901808', includeGstRate: false);

        $this->assertNull($fulfilment->commerceOrder?->items->first()?->gst_percentage);

        $this->actingAs($this->operator)
            ->withHeaders($this->dashboardAjaxHeaders())
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $invoice = StatutoryInvoice::query()->with('items')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('18.00', (string) $invoice->items->first()?->gst_percentage);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_dashboard_json_rejects_unreconciled_gst_amounts(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901809', includeGstRate: false);
        $item = $fulfilment->commerceOrder?->items->first();
        $item?->forceFill(['gst_percentage' => 18, 'tax_total' => 50])->save();

        $this->actingAs($this->operator)
            ->withHeaders($this->dashboardAjaxHeaders())
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment->fresh(['commerceOrder.items'])))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonFragment(['GST amount does not match taxable value × rate.']);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
    }

    public function test_dashboard_json_post_issues_qty_ten_inclusive_one_paisa_line(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901810', includeGstRate: false, lineOverrides: [
            'qty' => 10,
            'unit_price' => 2499.00,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
            'sku' => '946',
            'model_id' => 946,
        ], serialCount: 10);

        $this->actingAs($this->operator)
            ->withHeaders($this->dashboardAjaxHeaders())
            ->post(route('inventory.hardware-fulfilments.invoice.store', $fulfilment))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $invoice = StatutoryInvoice::query()->with('items')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('21177.97', (string) $invoice->taxable_value);
        $this->assertSame('3812.03', (string) $invoice->igst);
        $this->assertSame('24990.00', (string) $invoice->invoice_value);
        $this->assertSame('21177.96', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
        $this->assertSame('3812.04', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->tax_total);
    }

    /**
     * @return array<string, string>
     */
    private function dashboardAjaxHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    private function prepareIssuable(
        string $sourceId,
        bool $includeGstRate = true,
        array $lineOverrides = [],
        int $serialCount = 1,
    ): HardwareFulfilment {
        $fulfilment = $this->ingestHardware($sourceId, $includeGstRate, $lineOverrides);
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $item = $fulfilment->fresh()->commerceOrder?->items->first();
        for ($position = 1; $position <= $serialCount; $position++) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'commerce_order_item_id' => $item?->id,
                'line_no' => 1,
                'position' => $position,
                'serial_number' => 'SN-'.$sourceId.'-'.$position,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function assignBranch(HardwareFulfilment $fulfilment, string $code): void
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->operator->id,
            'branch_id' => $branch->id,
        ]);
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     */
    private function ingestHardware(string $sourceId, bool $includeGstRate = true, array $lineOverrides = []): HardwareFulfilment
    {
        $line = array_merge([
            'description' => 'MSO1300',
            'sku' => '951',
            'qty' => 1,
            'unit_price' => 3049,
            'hsn_sac' => '84716050',
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'line_total' => 3049,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 951,
        ], $lineOverrides);
        if ($includeGstRate) {
            $line['gst_percentage'] = $line['gst_percentage'] ?? 18;
        }

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
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Madhya Pradesh',
            'lines' => [$line],
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
