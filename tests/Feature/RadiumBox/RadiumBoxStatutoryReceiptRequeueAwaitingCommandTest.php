<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\OutboxEventStatus;
use App\Enums\RadiumBox\RadiumBoxStatutoryReceiptPublicationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\OutboxEvent;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationOutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class RadiumBoxStatutoryReceiptRequeueAwaitingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-31 14:00:00');

        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-statutory-token',
            'radiumbox.statutory_receipt_publish.schedule_on_issue' => true,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_is_registered_and_documents_options(): void
    {
        $this->artisan('desk:radiumbox-statutory-receipt-requeue-awaiting', ['--help' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('--dry-run')
            ->expectsOutputToContain('--limit');
    }

    public function test_live_run_blocked_when_publisher_disabled(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->seedAwaitingActivationPublication('RB318001');
        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Completed, $outbox->status);

        $this->runRequeueCommand()
            ->assertFailed()
            ->expectsOutputToContain('requeued=0');

        $this->assertSame(OutboxEventStatus::Completed, $outbox->fresh()->status);
    }

    public function test_dry_run_reports_counts_without_mutation(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $this->seedAwaitingActivationPublication('RB318002');

        $outboxBefore = OutboxEvent::query()->count();
        $publicationBefore = RadiumBoxStatutoryReceiptPublication::query()->get();

        $this->runRequeueCommand(['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry_run=true')
            ->expectsOutputToContain('selected=1')
            ->expectsOutputToContain('requeueable=1')
            ->expectsOutputToContain('requeued=0');

        $this->assertSame($outboxBefore, OutboxEvent::query()->count());
        foreach ($publicationBefore as $row) {
            $fresh = $row->fresh();
            $this->assertSame($row->http_idempotency_key, $fresh->http_idempotency_key);
            $this->assertSame($row->status, $fresh->status);
        }
    }

    public function test_enabled_publisher_requeues_eligible_rows(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        $invoice = $this->seedAwaitingActivationPublication('RB318003');

        $this->runRequeueCommand()
            ->assertSuccessful()
            ->expectsOutputToContain('requeued=1');

        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);
    }

    public function test_limit_option_bounds_selection(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        $this->seedAwaitingActivationPublication('RB318004');
        $this->seedAwaitingActivationPublication('RB318005');
        $this->seedAwaitingActivationPublication('RB318006');

        $this->runRequeueCommand(['--limit' => 2])
            ->assertSuccessful()
            ->expectsOutputToContain('selected=2')
            ->expectsOutputToContain('requeued=2');

        $this->assertSame(2, OutboxEvent::query()
            ->where('event_type', RadiumBoxStatutoryReceiptPublicationOutboxWriter::EVENT_TYPE)
            ->where('status', OutboxEventStatus::Pending)
            ->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', RadiumBoxStatutoryReceiptPublicationOutboxWriter::EVENT_TYPE)
            ->where('status', OutboxEventStatus::Completed)
            ->count());
    }

    public function test_repeated_execution_does_not_duplicate_publication_rows(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        $this->seedAwaitingActivationPublication('RB318007');

        $this->runRequeueCommand()->assertSuccessful();
        $this->assertSame(1, RadiumBoxStatutoryReceiptPublication::query()->count());

        $this->runRequeueCommand()
            ->assertSuccessful()
            ->expectsOutputToContain('requeued=0');

        $this->assertSame(1, RadiumBoxStatutoryReceiptPublication::query()->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', RadiumBoxStatutoryReceiptPublicationOutboxWriter::EVENT_TYPE)
            ->count());
    }

    public function test_delivered_publications_are_not_selected(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        $invoice = $this->radiumBoxCommerceInvoice('RB318008', 'INV-673100', '10.00');
        $writer = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class);
        $writer->enqueue($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FC0'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::Delivered, RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->value('status'));

        $this->runRequeueCommand(['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('selected=0')
            ->expectsOutputToContain('requeueable=0');
    }

    public function test_http_idempotency_key_unchanged_after_requeue(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        $invoice = $this->seedAwaitingActivationPublication('RB318009');
        $keyBefore = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->value('http_idempotency_key');

        $this->runRequeueCommand()->assertSuccessful();

        $this->assertSame($keyBefore, RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->value('http_idempotency_key'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runRequeueCommand(array $parameters = []): PendingCommand
    {
        return $this->artisan('desk:radiumbox-statutory-receipt-requeue-awaiting', $parameters);
    }

    private function seedAwaitingActivationPublication(string $sourceId): StatutoryInvoice
    {
        $previousEnabled = config('radiumbox.statutory_receipt_publish.enabled');
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->radiumBoxCommerceInvoice($sourceId, 'INV-'.substr($sourceId, -6), '50.00');
        app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);
        app(OutboxProcessorService::class)->process(1);

        config(['radiumbox.statutory_receipt_publish.enabled' => $previousEnabled]);

        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation,
            RadiumBoxStatutoryReceiptPublication::query()
                ->where('statutory_invoice_id', $invoice->id)
                ->value('status'),
        );

        return $invoice;
    }

    private function radiumBoxCommerceInvoice(string $sourceId, string $invoiceNumber, string $invoiceValue): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'billing_address' => 'Addr',
            'taxable_value' => '100.00',
            'discount' => '0.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => $invoiceValue,
            'issued_at' => now(),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Goods',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => $invoiceValue,
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'line_total' => $invoiceValue,
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
