<?php

namespace Tests\Feature\Customer360;

use App\Data\WhatsAppTemplateDispatchResult;
use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\WhatsAppTemplate;
use App\Mail\StatutoryInvoiceMail;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\WhatsAppTemplateDispatcher;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\SystemSettingsService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Customer360StatutoryInvoiceActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private StatutoryInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 18:28:19');
        Storage::fake('local');
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'mail.enabled' => true,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_customer_360_shows_view_download_and_email_for_the_linked_invoice(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-1', 'c360-one@example.com');

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee($invoice->invoice_number, false)
            ->assertSee('data-customer-360-section="statutory-invoice"', false)
            ->assertSee('View', false)
            ->assertSee('Download PDF', false)
            ->assertSee('Email', false)
            ->assertSee('WhatsApp', false);
    }

    public function test_authorized_agent_can_view_and_download_the_desk_pdf(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-PDF', 'c360-pdf@example.com');

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360.invoices.pdf', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360.invoices.download', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="'.$invoice->invoice_number.'.pdf"');
    }

    public function test_email_attaches_the_persisted_desk_pdf(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-MAIL', 'c360-mail@example.com');

        $this->actingAs($this->agent)
            ->postJson(route('dashboard.service-cases.customer-360.invoices.email', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(StatutoryInvoiceMail::class, function (StatutoryInvoiceMail $mail) use ($invoice): bool {
            return $mail->hasTo('c360-mail@example.com')
                && str_contains($mail->envelope()->subject, $invoice->invoice_number);
        });
    }

    public function test_whatsapp_uses_the_configured_interakt_template(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-WA', 'c360-wa@example.com', '9000000101');
        $this->enableWhatsAppInvoiceTemplate();

        $this->mock(WhatsAppTemplateDispatcher::class, function ($mock) use ($incident): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->withArgs(function (WhatsAppTemplate $template, Incident $case) use ($incident): bool {
                    return $template === WhatsAppTemplate::StatutoryInvoice
                        && $case->id === $incident->id;
                })
                ->andReturn(WhatsAppTemplateDispatchResult::success(new WhatsAppTemplateDispatch));
        });

        $this->actingAs($this->agent)
            ->postJson(route('dashboard.service-cases.customer-360.invoices.whatsapp', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_whatsapp_is_unavailable_when_no_approved_template_exists(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-WA-OFF', 'c360-wa-off@example.com', '9000000102');

        $this->actingAs($this->agent)
            ->postJson(route('dashboard.service-cases.customer-360.invoices.whatsapp', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_another_customers_invoice_is_not_reachable_from_this_case(): void
    {
        [$incident] = $this->issuedCase('RD-C360-INV-A', 'a@example.com');
        [, $otherInvoice] = $this->issuedCase('RD-C360-INV-B', 'b@example.com');

        $this->actingAs($this->agent)
            ->get(route('dashboard.service-cases.customer-360.invoices.pdf', [
                'incident' => $incident,
                'invoice' => $otherInvoice,
            ]))
            ->assertNotFound();

        $this->actingAs($this->agent)
            ->postJson(route('dashboard.service-cases.customer-360.invoices.email', [
                'incident' => $incident,
                'invoice' => $otherInvoice,
            ]))
            ->assertNotFound();
    }

    public function test_guest_cannot_download_a_customer_360_invoice(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-GUEST', 'guest@example.com');

        $this->get(route('dashboard.service-cases.customer-360.invoices.pdf', [
            'incident' => $incident,
            'invoice' => $invoice,
        ]))->assertRedirect();
    }

    public function test_global_search_finds_the_case_by_statutory_invoice_number(): void
    {
        [$incident, $invoice] = $this->issuedCase('RD-C360-INV-SEARCH', 'search@example.com');

        $this->actingAs($this->agent)
            ->getJson(route('search.index', ['q' => $invoice->invoice_number]))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id);
    }

    /**
     * @return array{0: Incident, 1: StatutoryInvoice}
     */
    private function issuedCase(string $orderId, string $email, string $phone = '9000000099'): array
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'C360 Buyer',
            'customer_email' => $email,
            'customer_phone' => $phone,
            'status' => 'active',
            'created_by' => $this->agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Invoice case '.$orderId,
            'description' => 'Invoice case.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->agent->id,
            'updated_by' => $this->agent->id,
            'assigned_to_user_id' => $this->agent->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $orderId,
            'source_order_id' => $orderId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$orderId,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer_name' => 'C360 Buyer',
            'customer_phone' => $phone,
            'customer_email' => $email,
            'billing_state' => 'Maharashtra',
            'billing_address' => '312 dhamankar plaza, Bhiwandi',
            'branch_code' => 'MUMBAI',
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499.00,
            'ordered_at' => '2026-09-07 10:00:00',
            'received_at' => now(),
        ]);
        $commerce->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499.00,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($commerce->fresh(['items']), $this->agent);

        return [$incident, $invoice];
    }

    private function enableWhatsAppInvoiceTemplate(): void
    {
        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.statutory_invoice.enabled' => true,
            'interakt.templates.statutory_invoice.name' => 'tax_invoice_notice',
        ]);

        foreach ([
            'notifications.whatsapp.enabled' => true,
            'whatsapp.api_enabled' => true,
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value ? '1' : '0'],
            );
            app(SystemSettingsService::class)->forget($key);
        }
    }
}
