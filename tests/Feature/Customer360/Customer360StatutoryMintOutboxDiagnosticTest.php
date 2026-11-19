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

class Customer360StatutoryMintOutboxDiagnosticTest extends TestCase
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

    public function test_customer_360_shows_failed_mint_outbox_for_rb1040_pattern(): void
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
            'attempts' => 3,
            'available_at' => now(),
            'last_error' => 'Upstream RadiumBox payload missing service_commerce block for order RB1040.',
            'updated_at' => now(),
        ]);

        $before = OutboxEvent::query()->count();

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-c360-statutory-mint-outbox', false)
            ->assertSee('Mint failed', false)
            ->assertSee('RB1040', false)
            ->assertSee('SC67554', false)
            ->assertSee('26 Oct 2026', false)
            ->assertSee('service commerce data missing', false)
            ->assertSee('Statutory invoice mint failed', false)
            ->assertDontSee('RadiumBox payload', false);

        $this->assertSame($before, OutboxEvent::query()->count());
    }

    public function test_pending_and_processing_states_render_without_failure_reason(): void
    {
        foreach ([
            [OutboxEventStatus::Pending, 'Mint queued'],
            [OutboxEventStatus::Processing, 'Mint in progress'],
        ] as [$status, $label]) {
            [$incident, $order] = $this->serviceCase('RD-P19-34-'.$status->value, 'SC-P19-34-'.$status->value);

            OutboxEvent::query()->create([
                'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
                'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
                'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
                'aggregate_id' => $order->id,
                'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
                'status' => $status,
                'attempts' => 0,
                'available_at' => now(),
            ]);

            $this->actingAs($this->agent)
                ->get(route('dashboard.service-cases.customer-360', $incident))
                ->assertOk()
                ->assertSee($label, false)
                ->assertDontSee('Failure reason', false);
        }
    }

    public function test_completed_outbox_without_invoice_is_explained(): void
    {
        [$incident, $order] = $this->serviceCase('RD-P19-34-COMP', 'SC-P19-34-COMP');

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now(),
            'processed_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Mint completed', false)
            ->assertSee('no statutory invoice is linked', false);
    }

    public function test_missing_outbox_record_shows_unavailability_message(): void
    {
        $incident = $this->serviceCase('RD-P19-34-NONE', 'SC-P19-34-NONE')[0];

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-c360-statutory-mint-outbox', false)
            ->assertSee('No mint outbox record', false)
            ->assertSee('no statutory mint outbox record exists', false);
    }

    public function test_customer_360_rn387_blocked_issuance_shows_sanitized_failure_only(): void
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
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('linked commerce order', false)
            ->assertDontSee('rdservice_net', false)
            ->assertDontSee('Automatic statutory', false);
    }

    public function test_cross_customer_agent_cannot_access_statutory_mint_diagnostics(): void
    {
        [$incident, $order] = $this->serviceCase('RB1040-XC', 'SC-XC-01');

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

        $unauthorized = User::factory()->create(['is_active' => true]);

        $this->assertNull(
            app(Customer360StatutoryMintOutboxPresenter::class)->forIncident($incident, $unauthorized),
        );

        $this->actingAs($unauthorized)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertForbidden();
    }

    public function test_existing_statutory_invoice_hides_mint_outbox_block(): void
    {
        [$incident, $order] = $this->serviceCase('RD-P19-34-INV', 'SC-P19-34-INV');

        StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-P19-34-001',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::SupportOrder->value,
            'source_id' => $order->order_id,
            'support_order_id' => $order->id,
            'idempotency_key' => 'statutory:support-order:'.$order->id,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Mint diagnostic buyer',
            'billing_address' => '1 Test Street',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'invoice_value' => 118.00,
            'issued_at' => now(),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => ['order_pk' => $order->id, 'order_id' => $order->order_id, 'trigger' => 'invoice_retry'],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now(),
            'processed_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('INV-P19-34-001', false)
            ->assertDontSee('data-c360-statutory-mint-outbox', false);
    }

    public function test_unauthorized_user_does_not_receive_mint_outbox_payload(): void
    {
        [$incident, $order] = $this->serviceCase('RB1040-UNAUTH', 'SC67554-U');

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

        $viewer = User::factory()->create(['is_active' => true]);

        $payload = app(Customer360StatutoryMintOutboxPresenter::class)->forIncident($incident, $viewer);

        $this->assertNull($payload);

        $this->actingAs($viewer)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertForbidden();
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
            'customer_name' => 'Mint diagnostic buyer',
            'customer_email' => 'mint-'.$orderId.'@example.com',
            'customer_phone' => '9000001234',
            'status' => 'active',
            'created_by' => $this->agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Mint diagnostic '.$orderId,
            'description' => 'Mint diagnostic case.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->agent->id,
            'updated_by' => $this->agent->id,
            'assigned_to_user_id' => $this->agent->id,
        ]);

        return [$incident, $order];
    }
}
