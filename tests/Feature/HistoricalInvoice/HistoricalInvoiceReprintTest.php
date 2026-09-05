<?php

namespace Tests\Feature\HistoricalInvoice;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_statutory_invoice_search_redirects_historical_identifiers(): void
    {
        Http::fake();

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.index', ['q' => 'INV6745886']))
            ->assertRedirect(route('finance.invoices.historical', ['q' => 'INV6745886']));

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.index', ['q' => 'rd268507']))
            ->assertRedirect(route('finance.invoices.historical', ['q' => 'RD268507']));

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.index', ['q' => 'INV-07671']))
            ->assertOk()
            ->assertSee('Statutory invoices', false);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.index', ['q' => 'Nareshkumar']))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_header_search_offers_historical_reprint_when_no_desk_case_exists(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
            'https://rdservice.net/api/integrations/v1/rd-orders/RD268507' => Http::response($this->spokeOrderPayload('RD268507', 'INV6745886'), 200),
            'https://rdservice.in/*' => Http::response(['status' => 404], 404),
            'https://radiumbox.com/api/integrations/v1/rd-orders/*' => Http::response(['status' => 404], 404),
        ]);

        $this->actingAs($this->actor)
            ->getJson(route('search.index', ['q' => 'inv6745886']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('historical.query', 'INV6745886')
            ->assertJsonPath('historical.kind', 'invoice')
            ->assertJsonPath('historical.url', route('finance.invoices.historical', ['q' => 'INV6745886']))
            ->assertJsonMissingPath('intake');

        $this->actingAs($this->actor)
            ->getJson(route('search.index', ['q' => 'INV-07671']))
            ->assertOk()
            ->assertJsonMissingPath('historical');

        $this->actingAs($this->actor)
            ->get(route('search.index', ['q' => 'INV6745886']))
            ->assertRedirect(route('finance.invoices.historical', ['q' => 'INV6745886']));

        $this->actingAs($this->actor)
            ->getJson(route('search.index', ['q' => 'RD268507']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('historical.query', 'RD268507')
            ->assertJsonPath('historical.kind', 'order');
    }

    public function test_agent_header_search_does_not_offer_historical_reprint_without_desk_case(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
        ]);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'INV6745886']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonMissingPath('historical');
    }

    public function test_header_search_opens_customer_360_for_historical_invoice_and_order(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
            'https://rdservice.net/api/integrations/v1/rd-orders/RD268507' => Http::response($this->spokeOrderPayload('RD268507', 'INV6745886'), 200),
            'https://rdservice.in/*' => Http::response(['status' => 404], 404),
            'https://radiumbox.com/api/integrations/v1/rd-orders/*' => Http::response(['status' => 404], 404),
        ]);

        $incident = $this->createMappedHistoricalCase();
        $customer360Url = route('dashboard.service-cases.customer-360', [
            'incident' => $incident,
            'historical_invoice' => 'INV6745886',
        ]);

        foreach (['INV6745886', 'RD268507'] as $query) {
            $this->actingAs($this->actor)
                ->getJson(route('search.index', ['q' => $query]))
                ->assertOk()
                ->assertJsonPath('match_count', 1)
                ->assertJsonPath('incident_ids.0', $incident->id)
                ->assertJsonPath('results.0.type', 'service_case')
                ->assertJsonPath('results.0.customer', 'Nareshkumar')
                ->assertJsonPath('results.0.actions.historical_invoice', 'INV6745886')
                ->assertJsonPath('results.0.actions.customer_360_url', $customer360Url)
                ->assertJsonMissingPath('historical');
        }

        $this->actingAs($this->actor)
            ->get(route('search.index', ['q' => 'INV6745886']))
            ->assertRedirect(route('dashboard', ['q' => 'INV6745886']));
    }

    public function test_customer_360_shows_historical_invoice_and_read_only_print(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
        ]);

        $incident = $this->createMappedHistoricalCase();

        $this->actingAs($this->actor)
            ->get(route('dashboard.service-cases.customer-360', [
                'incident' => $incident,
                'historical_invoice' => 'INV6745886',
            ]))
            ->assertOk()
            ->assertSee('INV6745886', false)
            ->assertSee('read-only reprint', false)
            ->assertSee(route('finance.invoices.historical.print', 'INV6745886'), false)
            ->assertDontSee('admin.radiumbox.com', false);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical.print', 'INV6745886'))
            ->assertOk()
            ->assertSee('INV6745886', false)
            ->assertSee('Historical reprint', false)
            ->assertDontSee('admin.radiumbox.com', false);

        $this->assertDatabaseCount('statutory_invoices', 0);
        $this->assertSame('INV6745886', $this->invoicePayload()['data']['invoice']['invoice_number']);
    }

    public function test_current_desk_order_search_still_opens_customer_360_without_historical_lookup(): void
    {
        Http::fake();

        $incident = $this->createMappedHistoricalCase('RD3434509', 'Current Desk Customer');

        $this->actingAs($this->actor)
            ->getJson(route('search.index', ['q' => 'RD3434509']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertJsonPath(
                'results.0.actions.customer_360_url',
                route('dashboard.service-cases.customer-360', $incident),
            )
            ->assertJsonMissingPath('results.0.actions.historical_invoice')
            ->assertJsonMissingPath('historical');

        Http::assertNothingSent();
    }

    public function test_lowercase_historical_invoice_is_normalized(): void
    {
        Http::fake([
            'https://radiumbox.com/api/integrations/v1/historical-invoices/INV6745886' => Http::response($this->invoicePayload(), 200),
        ]);

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'inv6745886']))
            ->assertOk()
            ->assertSee('INV6745886', false)
            ->assertSee('Nareshkumar', false);
    }

    public function test_historical_invoice_timeout_is_source_unavailable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out');
        });

        $this->actingAs($this->actor)
            ->get(route('finance.invoices.historical', ['q' => 'INV6745886']))
            ->assertOk()
            ->assertSee('temporarily unavailable', false);
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

    private function createMappedHistoricalCase(string $orderId = 'RD3449705', string $customerName = 'Nareshkumar'): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => $customerName,
            'customer_phone' => '9999999999',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Historical mapped case',
            'description' => 'Historical mapped case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $this->actor->id,
            'assigned_to_user_id' => $this->actor->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function spokeOrderPayload(string $orderId, string $invoice): array
    {
        return [
            'status' => 200,
            'spec_version' => '1.0',
            'website_id' => 'rdservice.net',
            'message' => 'OK',
            'data' => [
                'correlation' => ['rdorderid' => $orderId],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'order_id' => $orderId,
                    'serial_no' => '7710951',
                    'product_name' => 'MFS110',
                    'status' => 'Completed',
                    'payment_status' => 'Paid',
                    'userdetails' => json_encode([
                        'name' => 'Nareshkumar',
                        'phone' => '9999999999',
                    ]),
                ],
                'order' => [
                    'id' => 268507,
                    'invoicecode' => $invoice,
                    'payment_status' => 'Paid',
                    'status' => 'Completed',
                ],
                'snapshot' => [
                    'rdorderid' => $orderId,
                    'serial_number' => '7710951',
                    'invoice_number' => $invoice,
                    'payment_status' => 'Paid',
                    'model' => 'MFS110',
                ],
            ],
        ];
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
