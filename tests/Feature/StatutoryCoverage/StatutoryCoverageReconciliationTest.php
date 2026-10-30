<?php

namespace Tests\Feature\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageReport;
use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryCoverage\StatutoryCoverageReconciliationService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatutoryCoverageReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        config([
            'statutory_coverage.timezone' => 'Asia/Kolkata',
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'test-token',
            'order_lookup.spokes.rdservice_in.enabled' => false,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'test-token',
        ]);

        Http::fake([
            'radiumbox.test/api/integrations/v1/statutory-obligations*' => Http::response([
                'complete' => true,
                'population' => ['snapshot_consistency' => 'best_effort', 'total_count' => 0],
                'data' => [],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_is_registered_and_runs_dry_run(): void
    {
        $exit = Artisan::call('desk:reconcile-statutory-coverage', [
            '--from' => '2026-10-01 00:00:00',
            '--to' => '2026-10-15 23:59:59',
            '--channel' => ['radiumsign_com'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Statutory coverage reconciliation', Artisan::output());
    }

    public function test_missing_commerce_for_paid_support_order_on_rdservice_in(): void
    {
        $this->paidSupportOrder('RD9001');

        $report = $this->runReport('rdservice_in');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value]);
        $this->assertTrue($report->channels[0]->sourcePopulation->supported);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
        $this->assertSame(
            'Spoke lookup client is not configured (missing base URL or token).',
            $report->channels[0]->sourcePopulation->failureReason,
        );
    }

    public function test_ineligible_commerce_without_invoice(): void
    {
        $this->commerce([
            'source_id' => 'RBP9001',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'invoice_eligible' => false,
            'status_reason' => 'Invoice eligibility incomplete: seller GSTIN.',
        ]);

        $report = $this->runReport('radiumbox_com');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::IneligibleCommerce->value]);
    }

    public function test_eligible_commerce_missing_invoice(): void
    {
        $this->commerce([
            'source_id' => 'RD9002',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'created_at' => '2026-10-06 09:00:00',
            'paid_at' => '2026-10-06 09:00:00',
        ]);

        $this->paidSupportOrder('RD9002');

        $report = $this->runReport('rdservice_in');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::EligibleMissingInvoice->value]);
    }

    public function test_issued_invoice_with_stale_spoke_sync_on_radiumbox(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-TEST-9003',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB9003',
            'idempotency_key' => 'statutory:rb9003',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'invoice_value' => 118,
            'issued_at' => now(),
        ]);

        $this->commerce([
            'source_id' => 'RB9003',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'statutory_invoice_id' => $invoice->id,
            'created_at' => '2026-10-07 09:00:00',
            'paid_at' => '2026-10-07 09:00:00',
        ]);

        $report = $this->runReport('radiumbox_com');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::IssuedSyncUnknown->value]);
    }

    public function test_issued_invoice_explicit_pending_spoke_sync_on_radiumbox(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-TEST-9003P',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB9003P',
            'idempotency_key' => 'statutory:rb9003p',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'invoice_value' => 118,
            'issued_at' => now(),
        ]);

        $this->commerce([
            'source_id' => 'RB9003P',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'statutory_invoice_id' => $invoice->id,
            'metadata' => ['statutory_coverage' => ['spoke_sync' => 'pending']],
            'created_at' => '2026-10-07 09:00:00',
            'paid_at' => '2026-10-07 09:00:00',
        ]);

        $report = $this->runReport('radiumbox_com');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::IssuedSyncPending->value]);
    }

    public function test_issued_invoice_marked_synchronized_via_metadata(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-TEST-9004',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB9004',
            'idempotency_key' => 'statutory:rb9004',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'invoice_value' => 118,
            'issued_at' => now(),
        ]);

        $this->commerce([
            'source_id' => 'RB9004',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'statutory_invoice_id' => $invoice->id,
            'metadata' => ['statutory_coverage' => ['spoke_sync' => 'synchronized']],
            'created_at' => '2026-10-08 09:00:00',
            'paid_at' => '2026-10-08 09:00:00',
        ]);

        $report = $this->runReport('radiumbox_com');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::IssuedSynchronized->value]);
    }

    public function test_unmatched_conflict_classification_enum_is_distinct(): void
    {
        $this->assertSame(
            'unmatched_conflict',
            StatutoryCoverageClassification::UnmatchedConflict->value,
        );
    }

    public function test_spoke_list_api_auth_failure_marks_partial_source_population(): void
    {
        config([
            'statutory_coverage.channels.rdservice_in.obligation_list_path' => '/api/integrations/v1/statutory-obligations',
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'test-token',
        ]);

        Http::fake([
            'rdservice.in.test/*' => Http::response([], 401),
        ]);

        $report = $this->runReport('rdservice_in');

        $this->assertFalse($report->channels[0]->sourcePopulation->complete);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
        $this->assertFalse($report->channels[0]->reportComplete);
        $this->assertTrue($report->channels[0]->sourcePopulation->supported);
        $this->assertStringContainsString('authentication', strtolower((string) $report->channels[0]->sourcePopulation->failureReason));
    }

    public function test_unsupported_source_population_marks_report_incomplete(): void
    {
        config(['statutory_coverage.channels.rdservice_in.obligation_list_path' => null]);

        $this->paidSupportOrder('RD9020');

        $report = $this->runReport('rdservice_in');

        $this->assertFalse($report->channels[0]->sourcePopulation->supported);
        $this->assertFalse($report->channels[0]->sourcePopulationComplete);
        $this->assertFalse($report->channels[0]->reportComplete);
        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value]);
        $this->assertContains('statutory_coverage:partial_report:rdservice_in', $report->watchdogSuggestions);
    }

    public function test_desk_pagination_partial_marks_report_incomplete(): void
    {
        $this->paidSupportOrder('RD9021');
        $this->paidSupportOrder('RD9022');
        $this->paidSupportOrder('RD9023');

        $service = app(StatutoryCoverageReconciliationService::class);
        $window = new StatutoryCoverageWindow(
            from: Carbon::parse('2026-10-01 00:00:00', 'Asia/Kolkata'),
            to: Carbon::parse('2026-10-31 23:59:59', 'Asia/Kolkata'),
            timezone: 'Asia/Kolkata',
        );

        $report = $service->reconcile(['rdservice_in'], $window, 1, 2, false, 50);
        $channel = $report->channels[0];

        $this->assertFalse($channel->deskPopulationComplete);
        $this->assertSame(2, $channel->deskPopulationCount);
        $this->assertSame(3, $channel->deskPopulationTotal);
        $this->assertFalse($channel->reportComplete);
    }

    public function test_json_output_is_machine_readable(): void
    {
        $exit = Artisan::call('desk:reconcile-statutory-coverage', [
            '--from' => '2026-10-01 00:00:00',
            '--to' => '2026-10-15 23:59:59',
            '--channel' => ['radiumsign_com'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('channels', $decoded);
        $this->assertArrayHasKey('report_complete', $decoded['channels'][0]);
    }

    public function test_invalid_date_window_returns_failure(): void
    {
        $exit = Artisan::call('desk:reconcile-statutory-coverage', [
            '--from' => '2026-10-20 00:00:00',
            '--to' => '2026-10-01 00:00:00',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_duplicate_commerce_rows_surface_conflict(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->dropUnique('commerce_orders_source_unique');
        });

        $first = $this->commerce(['source_id' => 'RD9024']);
        CommerceOrder::query()->create([
            'order_no' => 'CO-RD9024-DUP',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RD9024',
            'source_order_id' => 'RD9024',
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:RD9024:dup',
            'payload_hash' => hash('sha256', 'RD9024-dup'),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'created_at' => '2026-10-05 09:00:00',
            'paid_at' => '2026-10-05 09:00:00',
        ]);

        $this->assertSame(2, CommerceOrder::query()->where('source_id', 'RD9024')->count());

        $report = $this->runReport('rdservice_in');

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::UnmatchedConflict->value]);
    }

    public function test_exception_rows_are_capped(): void
    {
        $this->paidSupportOrder('RD9030');
        $this->paidSupportOrder('RD9031');
        $this->paidSupportOrder('RD9032');

        $service = app(StatutoryCoverageReconciliationService::class);
        $window = new StatutoryCoverageWindow(
            from: Carbon::parse('2026-10-01 00:00:00', 'Asia/Kolkata'),
            to: Carbon::parse('2026-10-31 23:59:59', 'Asia/Kolkata'),
            timezone: 'Asia/Kolkata',
        );

        $report = $service->reconcile(['rdservice_in'], $window, 1, 500, true, 2);

        $this->assertCount(2, $report->channels[0]->exceptions);
    }

    public function test_reconciliation_does_not_issue_http_mutations(): void
    {
        Http::fake();

        $this->paidSupportOrder('RD9040');

        $this->runReport('rdservice_in');

        Http::assertNothingSent();
    }

    public function test_watchdog_suggestions_for_gaps(): void
    {
        $this->paidSupportOrder('RD9010');

        $report = $this->runReport('rdservice_in');

        $this->assertContains('statutory_coverage:missing_commerce:rdservice_in', $report->watchdogSuggestions);
    }

    public function test_timezone_boundary_uses_configured_timezone(): void
    {
        $this->paidSupportOrder('RD9011', '2026-10-01 00:30:00');

        $service = app(StatutoryCoverageReconciliationService::class);
        $window = new StatutoryCoverageWindow(
            from: Carbon::parse('2026-10-01 00:00:00', 'Asia/Kolkata'),
            to: Carbon::parse('2026-10-01 01:00:00', 'Asia/Kolkata'),
            timezone: 'Asia/Kolkata',
        );

        $report = $service->reconcile(['rdservice_in'], $window, 1, 500, false, 50);

        $this->assertSame(1, $report->channels[0]->counts[StatutoryCoverageClassification::MissingCommerce->value] ?? 0);
    }

    private function runReport(string $channel): StatutoryCoverageReport
    {
        $service = app(StatutoryCoverageReconciliationService::class);
        $window = new StatutoryCoverageWindow(
            from: Carbon::parse('2026-10-01 00:00:00', 'Asia/Kolkata'),
            to: Carbon::parse('2026-10-31 23:59:59', 'Asia/Kolkata'),
            timezone: 'Asia/Kolkata',
        );

        return $service->reconcile([$channel], $window, 1, 500, false, 50);
    }

    private function paidSupportOrder(string $orderId, ?string $paymentDate = null): Order
    {
        $actor = User::factory()->create(['is_active' => true]);

        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_'.$orderId,
            'transaction_id' => 'TXN-'.$orderId,
            'payment_date' => $paymentDate ?? '2026-10-10 10:00:00',
            'completed_at' => now(),
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function commerce(array $overrides): CommerceOrder
    {
        $sourceId = (string) ($overrides['source_id'] ?? 'RD0000');
        $channel = $overrides['channel'] ?? StatutoryInvoiceChannel::RdServiceIn;
        $channelValue = $channel instanceof StatutoryInvoiceChannel ? $channel->value : (string) $channel;

        return CommerceOrder::query()->create(array_merge([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:'.$channelValue.':commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'created_at' => '2026-10-05 09:00:00',
            'paid_at' => '2026-10-05 09:00:00',
        ], $overrides));
    }
}
