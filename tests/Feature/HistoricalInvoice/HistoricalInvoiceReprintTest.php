<?php

namespace Tests\Feature\HistoricalInvoice;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoricalInvoiceReprintTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRetiredAdminOrderFallback();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.token' => 'test-desk-order-token',
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.com',
            'rdservice.enabled' => true,
            'rdservice.token' => 'test-desk-order-token',
            'rdservice.base_url' => 'https://rdservice.net',
        ]);
    }

    public function test_valid_historical_invoice_can_be_reprinted(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'INV6745886']))
            ->assertOk()
            ->assertSee('INV6745886', false)
            ->assertSee('Nareshkumar', false)
            ->assertDontSee('admin.radiumbox.com', false);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical.print', 'INV6745886'))
            ->assertOk()
            ->assertSee('INV6745886', false)
            ->assertSee('Historical reprint', false)
            ->assertSee('MFS110', false);
    }

    public function test_unauthorized_user_cannot_open_historical_reprint(): void
    {
        $this->get(route('finance.invoices.historical'))->assertRedirect();

        $guest = User::factory()->create(['is_active' => true]);
        $this->actingAs($guest)
            ->get(route('finance.invoices.historical', ['q' => 'INV6745886']))
            ->assertForbidden();
    }

    public function test_invalid_and_non_reprintable_cases(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV0000001' => Http::response([
                'status' => 404,
                'eligibility' => 'not_found',
                'message' => 'Historical invoice not found',
            ], 404),
            'https://radiumbox.com/api/integrations/v1/historical-invoices/*' => Http::response([
                'status' => 409,
                'eligibility' => 'paid_without_invoice',
                'data' => ['invoice_number' => null],
            ], 409),
            'https://rdservice.net/*' => Http::response(['status' => 404], 404),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'INV-07671']))
            ->assertOk()
            ->assertSee('statutory series', false);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'INV0000001']))
            ->assertOk()
            ->assertSee('No historical invoice', false);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical.print', 'INV0000001'))
            ->assertNotFound();
    }

    public function test_lookup_does_not_create_invoices(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'INV6745886']))
            ->assertOk();

        $this->assertDatabaseCount('statutory_invoices', 0);
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(): array
    {
        return [
            'status' => 200,
            'eligibility' => 'historical_invoice',
            'data' => [
                'invoice' => [
                    'invoice_number' => 'INV6745886',
                    'invoice_date' => '2025-01-15 10:00:00',
                    'orders_id' => 268507,
                    'ordercode' => 'RD268507',
                    'rdorderid' => 'RD3449705',
                    'seller' => [
                        'legal_name' => 'Phil Technologies (P) Limited',
                        'gstin' => '07AAICP1128M1Z9',
                        'address' => 'Delhi',
                    ],
                    'buyer' => [
                        'name' => 'Nareshkumar',
                        'gst_no' => null,
                    ],
                    'lines' => [[
                        'product_name' => 'MFS110',
                        'hsn_code' => '8471',
                        'qty' => 1,
                        'price' => 408,
                        'tax' => 73,
                        'total' => 481,
                    ]],
                    'totals' => ['tax' => 73, 'total' => 481],
                    'read_only' => true,
                ],
            ],
        ];
    }
}
