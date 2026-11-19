<?php

namespace Tests\Feature\Customer360;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Customer360\Customer360StatutoryMintOutboxPresenter;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxWriter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360DrawerStatutoryInvoiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-26 13:31:51');
        $this->seed(RolePermissionSeeder::class);

        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_drawer_rb1040_shows_invoice_section_and_failed_mint_diagnostics(): void
    {
        [$incident, $order] = $this->serviceCase('RB1040', 'SC67554');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => [
                'order_pk' => $order->id,
                'order_id' => $order->order_id,
                'trigger' => 'service_order_payment',
            ],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'Upstream RadiumBox service lookup for RB1040 missing service_commerce block.',
            'updated_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->withHeaders([
                'Accept' => 'text/html',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('data-customer-360-section="statutory-invoice"', false)
            ->assertSee('data-c360-statutory-mint-outbox', false)
            ->assertSee('Mint failed', false)
            ->assertSee('RB1040', false)
            ->assertSee('SC67554', false)
            ->assertSee('service commerce data missing', false)
            ->assertDontSee('RadiumBox', false)
            ->assertDontSee('service_commerce', false);
    }

    public function test_drawer_rn387_shows_invoice_section_and_linked_commerce_failure(): void
    {
        [$incident, $order] = $this->serviceCase('RN387', 'SC75934');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_reconciliation'],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'Automatic statutory invoice issuance for RN387 (rdservice_net) is blocked: no linked commerce order.',
        ]);

        $this->actingAs($this->agent)
            ->withHeaders([
                'Accept' => 'text/html',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-customer-360-section="statutory-invoice"', false)
            ->assertSee('linked commerce order', false)
            ->assertDontSee('rdservice_net', false)
            ->assertDontSee('Automatic statutory', false);
    }

    public function test_order_customer_360_route_matches_incident_drawer_payload(): void
    {
        [$incident, $order] = $this->serviceCase('RB1040-ORDER-ROUTE', 'SC-ORDER-ROUTE');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'missing service_commerce',
        ]);

        $incidentHtml = $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $orderHtml = $this->actingAs($this->agent)
            ->get(route('dashboard.orders.customer-360', $order))
            ->assertOk()
            ->getContent();

        $this->assertSame($incidentHtml, $orderHtml);
    }

    public function test_drawer_presenter_and_rendered_html_share_authoritative_mint_status(): void
    {
        [$incident, $order] = $this->serviceCase('RN387-PARITY', 'SC-PARITY');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'no linked commerce order',
        ]);

        $presenter = app(Customer360StatutoryMintOutboxPresenter::class)->forIncident($incident, $this->agent);

        $this->assertIsArray($presenter);
        $this->assertSame('Mint failed', $presenter['status_label'] ?? null);

        $html = $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Mint failed', $html);
        $this->assertStringContainsString((string) ($presenter['failure_reason'] ?? ''), $html);
    }

    public function test_drawer_with_statutory_invoice_keeps_document_actions_without_mint_block(): void
    {
        [$incident, $order] = $this->serviceCase('RD-INV-DRAWER', 'SC-INV-DRAWER');

        StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-DRAWER-001',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::SupportOrder->value,
            'source_id' => $order->order_id,
            'support_order_id' => $order->id,
            'idempotency_key' => 'statutory:support-order:'.$order->id,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Drawer invoice buyer',
            'billing_address' => '1 Test Street',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'invoice_value' => 118.00,
            'issued_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-customer-360-section="statutory-invoice"', false)
            ->assertSee('INV-DRAWER-001', false)
            ->assertSee('data-c360-invoice-view', false)
            ->assertDontSee('data-c360-statutory-mint-outbox', false);
    }

    public function test_drawer_without_invoice_and_without_outbox_shows_accurate_empty_state(): void
    {
        $incident = $this->serviceCase('RD-EMPTY-DRAWER', 'SC-EMPTY-DRAWER')[0];

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-customer-360-section="statutory-invoice"', false)
            ->assertSee('data-c360-statutory-mint-outbox', false)
            ->assertSee('No mint outbox record', false)
            ->assertSee('no statutory mint outbox record exists', false);
    }

    public function test_drawer_read_does_not_create_or_mutate_outbox_rows(): void
    {
        [$incident, $order] = $this->serviceCase('RB1040-READONLY', 'SC-READONLY');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'missing service_commerce',
        ]);

        $snapshot = OutboxEvent::query()->orderBy('id')->pluck('updated_at', 'id');

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk();

        $this->assertEquals($snapshot, OutboxEvent::query()->orderBy('id')->pluck('updated_at', 'id'));
    }

    public function test_unrelated_incident_drawer_does_not_surface_other_order_mint_diagnostics(): void
    {
        [$ownerIncident, $ownerOrder] = $this->serviceCase('RB1040-ISO-A', 'SC-ISO-A');
        [$otherIncident] = $this->serviceCase('RB1040-ISO-B', 'SC-ISO-B');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($ownerOrder->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $ownerOrder->id,
            'payload' => ['order_pk' => $ownerOrder->id, 'order_id' => $ownerOrder->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'missing service_commerce',
        ]);

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $otherIncident))
            ->assertOk()
            ->assertSee('data-customer-360-section="statutory-invoice"', false)
            ->assertDontSee('RB1040-ISO-A', false);
    }

    public function test_drawer_html_excludes_retry_and_mint_action_controls(): void
    {
        [$incident, $order] = $this->serviceCase('RB1040-NO-RETRY', 'SC-NO-RETRY');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Failed,
            'attempts' => 1,
            'available_at' => now(),
            'last_error' => 'missing service_commerce',
        ]);

        $html = $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/data-(?:retry|mint|requeue)/i', $html);
        $this->assertStringNotContainsString('Retry mint', $html);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function serviceCase(string $orderId, string $referenceNo): array
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Drawer visibility buyer',
            'customer_email' => 'drawer-'.$orderId.'@example.com',
            'customer_phone' => '9000001234',
            'status' => 'active',
            'created_by' => $this->agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Drawer visibility '.$orderId,
            'description' => 'Drawer visibility case.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->agent->id,
            'updated_by' => $this->agent->id,
            'assigned_to_user_id' => $this->agent->id,
        ]);

        return [$incident, $order];
    }
}
