<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationDispatchReservation;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Reservation semantics use {@see lockForUpdate()} (InnoDB row locks in production MySQL/MariaDB).
 * PHPUnit uses SQLite; these tests prove sequential atomicity, not multi-process concurrency.
 */
class StatutoryInvoiceCancellationDispatchReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_increments_attempt_count_under_row_lock(): void
    {
        $operation = $this->createOperation(attemptCount: 0);

        $reserved = $this->reservation()->reserve($operation->id, 3);

        $this->assertSame(1, $reserved->attempt_count);
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::IrnPending, $reserved->status);
    }

    public function test_reserve_at_dispatch_limit_moves_to_manual_review_without_increment(): void
    {
        $operation = $this->createOperation(attemptCount: 2);

        Log::spy();

        try {
            $this->reservation()->reserve($operation->id, 2);
            $this->fail('Expected validation');
        } catch (ValidationException) {
        }

        $operation->refresh();
        $this->assertSame(2, $operation->attempt_count);
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
        Log::shouldHaveReceived('warning')->with('statutory_invoice_cancellation.manual_review_required', \Mockery::any());
    }

    public function test_reserve_rejects_when_already_manual_review(): void
    {
        $operation = $this->createOperation(
            attemptCount: 1,
            status: StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
        );

        $this->expectException(ValidationException::class);
        $this->reservation()->reserve($operation->id, 3);
    }

    public function test_sequential_reservations_cannot_exceed_configured_maximum(): void
    {
        $operation = $this->createOperation(attemptCount: 0);

        $this->reservation()->reserve($operation->id, 2);
        $this->reservation()->reserve($operation->id, 2);

        $operation->refresh();
        $this->assertSame(2, $operation->attempt_count);

        try {
            $this->reservation()->reserve($operation->id, 2);
            $this->fail('Expected validation at cap');
        } catch (ValidationException) {
        }

        $operation->refresh();
        $this->assertSame(2, $operation->attempt_count);
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
    }

    private function reservation(): StatutoryInvoiceCancellationDispatchReservation
    {
        return app(StatutoryInvoiceCancellationDispatchReservation::class);
    }

    private function createOperation(
        int $attemptCount,
        StatutoryInvoiceCancellationOperationStatus $status = StatutoryInvoiceCancellationOperationStatus::Requested,
    ): StatutoryInvoiceCancellation {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-RES-'.uniqid(),
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => \App\Enums\StatutoryInvoiceStatus::Issued,
            'channel' => \App\Enums\StatutoryInvoiceChannel::DeskPos,
            'source_type' => \App\Enums\StatutoryInvoiceSourceType::InventorySale,
            'source_id' => 'SALE-RES',
            'idempotency_key' => 'test:res:'.uniqid(),
            'buyer_gstin' => '07AABCU9603R1ZM',
            'buyer_name' => 'Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'invoice_value' => 118,
            'tax_total' => 18,
            'issued_at' => now(),
        ]);

        return StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => 'cancel:res:'.uniqid(),
            'actor_id' => $admin->id,
            'reason' => 'Reservation test',
            'status' => $status,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempt_count' => $attemptCount,
        ]);
    }
}
