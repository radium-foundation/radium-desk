<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\FinanceJournal;
use App\Models\InvoiceSequence;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_distinct_sources_on_the_same_channel_allocate_monotonic_numbers(): void
    {
        $this->enableTestSeries();

        $first = $this->invoices->mint($this->request('sale-a'), $this->actor);
        $second = $this->invoices->mint($this->request('sale-b'), $this->actor);

        $this->assertSame('TEST-00001', $first->invoice_number);
        $this->assertSame('TEST-00002', $second->invoice_number);
        $this->assertSame(2, StatutoryInvoice::query()->count());
        $this->assertSame(2, (int) InvoiceSequence::query()->value('current_value'));
    }

    public function test_minting_fails_closed_when_legal_series_is_unset(): void
    {
        $this->expectException(ValidationException::class);

        $this->invoices->mint($this->request('1'), $this->actor);
    }

    public function test_mint_is_idempotent_for_the_same_channel_source(): void
    {
        $this->enableTestSeries();

        $first = $this->invoices->mint($this->request('sale-1'), $this->actor);
        $second = $this->invoices->mint($this->request('sale-1'), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('TEST-00001', $first->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequence::query()->value('current_value'));
        $this->assertNull($first->finance_journal_id);
        $this->assertSame(0, FinanceJournal::query()->where('source_type', 'statutory_invoice')->count());
    }

    public function test_different_channels_with_the_same_source_id_get_isolated_invoices(): void
    {
        $this->enableTestSeries();

        $pos = $this->invoices->mint($this->request('100', StatutoryInvoiceChannel::DeskPos), $this->actor);
        $net = $this->invoices->mint($this->request('100', StatutoryInvoiceChannel::RdServiceNet), $this->actor);

        $this->assertNotSame($pos->id, $net->id);
        $this->assertSame('TEST-00001', $pos->invoice_number);
        $this->assertSame('TEST-00002', $net->invoice_number);
        $this->assertSame(2, StatutoryInvoice::query()->count());
    }

    public function test_failed_outer_transaction_rolls_back_number_allocation(): void
    {
        $this->enableTestSeries();

        try {
            DB::transaction(function (): void {
                $this->invoices->mint($this->request('rollback-1'), $this->actor);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, (int) InvoiceSequence::query()->value('current_value'));
    }

    public function test_posted_invoice_number_is_immutable_and_cannot_be_deleted(): void
    {
        $this->enableTestSeries();
        $invoice = $this->invoices->mint($this->request('sale-lock'), $this->actor);

        try {
            $invoice->update(['invoice_number' => 'HACK-1']);
            $this->fail('Expected immutability guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        try {
            $invoice->delete();
            $this->fail('Expected delete guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $this->assertSame('TEST-00001', $invoice->fresh()->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_number_format_matching_pos_internal_receipts_is_rejected(): void
    {
        config([
            'statutory_invoices.series_code' => 'INV',
            'statutory_invoices.number_format' => 'INV-HQ-2026-{seq:5}',
        ]);

        $this->expectException(ValidationException::class);
        $this->invoices->mint($this->request('blocked-format'), $this->actor);
    }

    public function test_cancel_keeps_the_allocated_number(): void
    {
        $this->enableTestSeries();
        $invoice = $this->invoices->mint($this->request('cancel-me'), $this->actor);
        $cancelled = $this->invoices->cancel($invoice, $this->actor, 'Test cancel');

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('TEST-00001', $cancelled->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_post_finance_journals_flag_fails_closed(): void
    {
        $this->enableTestSeries();
        config(['statutory_invoices.post_finance_journals' => true]);

        $this->expectException(ValidationException::class);
        $this->invoices->mint($this->request('journal-block'), $this->actor);
    }

    private function enableTestSeries(): void
    {
        config([
            'statutory_invoices.series_code' => 'TEST',
            'statutory_invoices.number_format' => '{series}-{seq:5}',
            'statutory_invoices.document_type' => 'tax_invoice',
            'statutory_invoices.gstin_scope' => '',
            'statutory_invoices.financial_year' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
        ]);
    }

    private function request(
        string $sourceId,
        StatutoryInvoiceChannel $channel = StatutoryInvoiceChannel::DeskPos,
    ): StatutoryInvoiceMintRequest {
        return new StatutoryInvoiceMintRequest(
            channel: $channel,
            sourceType: StatutoryInvoiceSourceType::External,
            sourceId: $sourceId,
            lines: [
                new StatutoryInvoiceLineDraft(
                    description: 'Test line',
                    qty: 1,
                    unitPrice: 100,
                    gstPercentage: 18,
                    taxTotal: 18,
                    lineTotal: 118,
                    taxableValue: 100,
                    hsnSac: '84716050',
                ),
            ],
        );
    }
}
