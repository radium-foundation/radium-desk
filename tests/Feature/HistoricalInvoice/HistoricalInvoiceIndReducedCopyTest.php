<?php

namespace Tests\Feature\HistoricalInvoice;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\HistoricalInvoice\HistoricalInvoiceIndReducedCopyAudit;
use App\Services\HistoricalInvoice\IndReducedCopyDisclosure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoricalInvoiceIndReducedCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->disableRetiredAdminOrderFallback();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.token' => 'test-desk-order-token',
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.com',
            'rdservice.enabled' => false,
            'historical_invoices.ind_reduced_copy.enabled' => true,
        ]);
    }

    public function test_rde_with_ind_reference_uses_order_based_lookup_and_shows_disclaimer(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response($this->indReducedInvoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'RDE287954']))
            ->assertOk()
            ->assertSee('IND671362', false)
            ->assertSee(IndReducedCopyDisclosure::PRIMARY_DISCLAIMER, false)
            ->assertSee('Hardware serial numbers', false);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'historical-invoices')
                && str_contains($request->url(), 'orderid=RDE287954');
        });

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->actor->id,
            'event' => HistoricalInvoiceIndReducedCopyAudit::EVENT_VIEWED,
        ]);
    }

    public function test_ind_invoice_code_search_is_rejected(): void
    {
        Http::fake();

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'IND671362']))
            ->assertOk()
            ->assertSee('not the IND invoice code', false);

        Http::assertNothingSent();
    }

    public function test_gate_off_preserves_legacy_paid_without_invoice_message(): void
    {
        config(['historical_invoices.ind_reduced_copy.enabled' => false]);

        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'RDE287954']))
            ->assertOk()
            ->assertSee('no historical invoice number', false);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'historical-invoices'));
    }

    public function test_missing_box_payload_fails_closed(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response([
                'status' => 409,
                'eligibility' => 'paid_without_invoice',
                'message' => 'Order is paid but has no historical invoice.',
            ], 409),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'RDE287954']))
            ->assertOk()
            ->assertSee('Stored invoice data', false);
    }

    public function test_http_box_endpoint_is_rejected_before_historical_dispatch(): void
    {
        config([
            'order_lookup.spokes.radiumbox_com.base_url' => 'http://127.0.0.1',
            'order_lookup.spokes.radiumbox_com.host' => 'radiumbox.com',
        ]);

        Http::fake([
            'http://127.0.0.1/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'RDE287954']))
            ->assertOk()
            ->assertSee('Stored invoice data could not be retrieved', false);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'historical-invoices'));
    }

    public function test_print_records_audit_and_shows_duplicate_footer_only(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response($this->indReducedInvoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical.print', 'RDE287954'))
            ->assertOk()
            ->assertSee(IndReducedCopyDisclosure::PRINT_FOOTER_DUPLICATE_NOTICE, false)
            ->assertSee('Scanner Product', false)
            ->assertSee('2,799.00', false)
            ->assertDontSee(IndReducedCopyDisclosure::PRIMARY_DISCLAIMER, false)
            ->assertDontSee('CGST / SGST / IGST split', false)
            ->assertDontSee('Reduced copy reference', false)
            ->assertDontSee('Legacy IND reference', false)
            ->assertDontSee('Amounts shown as returned by the source invoice', false)
            ->assertDontSee('border: 2px solid #c8102e', false);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->actor->id,
            'event' => HistoricalInvoiceIndReducedCopyAudit::EVENT_PRINTED,
        ]);
    }

    public function test_unauthorized_user_is_denied(): void
    {
        Http::fake();

        $guest = User::factory()->create(['is_active' => true]);
        $this->actingAs($guest)
            ->get(route('finance.invoices.historical', ['q' => 'RDE287954']))
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_ind_print_does_not_use_tax_invoice_kicker(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response($this->indReducedInvoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical.print', 'RDE287954'))
            ->assertOk()
            ->assertSee('IND671362', false)
            ->assertDontSee('doc-kicker">Tax invoice', false)
            ->assertSee(IndReducedCopyDisclosure::PRINT_FOOTER_DUPLICATE_NOTICE, false);
    }

    public function test_box_invoice_number_mismatch_versus_enrichment_fails_closed(): void
    {
        $payload = $this->indReducedInvoicePayload();
        $payload['data']['invoice']['invoice_number'] = 'IND999999';

        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE287954' => Http::response($this->boxOrderPayload('RDE287954', 'IND671362'), 200),
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response($payload, 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'RDE287954']))
            ->assertOk()
            ->assertSee('does not match the order record', false);

        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $this->actor->id,
            'event' => HistoricalInvoiceIndReducedCopyAudit::EVENT_VIEWED,
        ]);
    }

    public function test_rde152000_production_shape_ind567881_uses_order_lookup_and_reprint(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/rd-orders/RDE152000' => Http::response($this->rde152000BoxOrderPayload(), 200),
            'https://radiumbox.com/api/integrations/v1/historical-invoices*' => Http::response($this->rde152000HistoricalInvoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'RDE152000']))
            ->assertOk()
            ->assertSee('IND567881', false)
            ->assertSee(IndReducedCopyDisclosure::PRIMARY_DISCLAIMER, false)
            ->assertSee('Fields omitted from this copy', false)
            ->assertSee('Hardware serial numbers', false)
            ->assertSee('3048', false)
            ->assertDontSee('eligible for reprint. The number is unchanged', false);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'historical-invoices')
                && str_contains($request->url(), 'orderid=RDE152000');
        });

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->actor->id,
            'event' => HistoricalInvoiceIndReducedCopyAudit::EVENT_VIEWED,
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical.print', 'RDE152000'))
            ->assertOk()
            ->assertSee('IND567881', false)
            ->assertSee('RDE152000', false)
            ->assertSee('28 Nov 2025', false)
            ->assertSee('Hardware line item', false)
            ->assertSee(IndReducedCopyDisclosure::PRINT_FOOTER_DUPLICATE_NOTICE, false)
            ->assertDontSee(IndReducedCopyDisclosure::PRIMARY_DISCLAIMER, false)
            ->assertDontSee('Reduced copy reference', false)
            ->assertDontSee('Amounts shown as returned by the source invoice', false);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->actor->id,
            'event' => HistoricalInvoiceIndReducedCopyAudit::EVENT_PRINTED,
        ]);
    }

    public function test_inv_historical_lookup_still_uses_invoice_path(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response([
                'status' => 200,
                'eligibility' => 'historical_invoice',
                'data' => [
                    'invoice' => [
                        'invoice_number' => 'INV6745886',
                        'invoice_date' => '2025-01-15 10:00:00',
                        'orders_id' => 1,
                        'ordercode' => 'RD1',
                        'seller' => ['legal_name' => 'Phil Technologies (P) Limited'],
                        'buyer' => ['name' => 'Buyer'],
                        'lines' => [],
                        'totals' => ['total' => 100],
                        'read_only' => true,
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'INV6745886']))
            ->assertOk()
            ->assertSee('INV6745886', false)
            ->assertDontSee(IndReducedCopyDisclosure::PRIMARY_DISCLAIMER, false);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'historical-invoices/INV6745886'));
    }

    /**
     * @return array<string, mixed>
     */
    private function boxOrderPayload(string $orderId, string $invoice): array
    {
        return [
            'status' => 200,
            'data' => [
                'correlation' => ['rdorderid' => $orderId, 'ordercode' => $orderId],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'status' => 'Shipped',
                    'payment_status' => 'Paid',
                    'userdetails' => json_encode(['name' => 'Buyer Example', 'phone' => '9000000000']),
                ],
                'order' => [
                    'id' => 287954,
                    'ordercode' => $orderId,
                    'invoicecode' => $invoice,
                    'payment_status' => 'Paid',
                    'status' => 'Shipped',
                ],
                'snapshot' => [
                    'invoice_number' => $invoice,
                    'payment_status' => 'Paid',
                    'rd_order_status' => 'Shipped',
                ],
            ],
        ];
    }

    /**
     * Sanitized production-shaped fixture (P-24-16/17); generic buyer label only.
     *
     * @return array<string, mixed>
     */
    private function rde152000BoxOrderPayload(): array
    {
        $payload = $this->boxOrderPayload('RDE152000', 'IND567881');
        $payload['data']['order']['id'] = 152000;
        $payload['data']['correlation']['orders_id'] = 152000;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function rde152000HistoricalInvoicePayload(): array
    {
        return [
            'status' => 200,
            'eligibility' => 'historical_invoice',
            'data' => [
                'invoice' => [
                    'invoice_number' => 'IND567881',
                    'invoice_date' => '2025-11-28 10:00:26',
                    'orders_id' => 152000,
                    'ordercode' => 'RDE152000',
                    'seller' => [
                        'legal_name' => 'Phil Technologies (P) Limited',
                        'gstin' => '07AAICP1128M1Z9',
                        'address' => 'Delhi address',
                    ],
                    'buyer' => ['name' => 'Buyer Example'],
                    'lines' => [[
                        'id' => 1,
                        'product_name' => 'Hardware line item',
                        'hsn_code' => '8471',
                        'productid' => 1,
                        'qty' => 1,
                        'price' => '3048',
                        'tax' => '464.949',
                        'total' => '3048',
                    ]],
                    'totals' => [
                        'subtotal' => '3048',
                        'shipping' => null,
                        'discount' => '0',
                        'tax' => '464.949',
                        'total' => '3048',
                    ],
                    'payment_status' => 'Paid',
                    'status' => 'Shipped',
                    'einvoice' => null,
                    'read_only' => true,
                ],
            ],
        ];
    }

    private function indReducedInvoicePayload(): array
    {
        return [
            'status' => 200,
            'eligibility' => 'historical_invoice',
            'data' => [
                'invoice' => [
                    'invoice_number' => 'IND671362',
                    'invoice_date' => '2026-08-01 11:28:06',
                    'orders_id' => 287954,
                    'ordercode' => 'RDE287954',
                    'seller' => [
                        'legal_name' => 'Phil Technologies (P) Limited',
                        'gstin' => '07AAICP1128M1Z9',
                        'address' => 'Delhi address',
                    ],
                    'buyer' => ['name' => 'Buyer Example'],
                    'lines' => [[
                        'product_name' => 'Scanner Product',
                        'hsn_code' => '8471',
                        'qty' => 1,
                        'price' => 2372.03,
                        'tax' => 426.97,
                        'total' => 2799,
                    ]],
                    'totals' => ['tax' => 426.97, 'total' => 2799],
                    'einvoice' => null,
                    'read_only' => true,
                ],
            ],
        ];
    }
}
