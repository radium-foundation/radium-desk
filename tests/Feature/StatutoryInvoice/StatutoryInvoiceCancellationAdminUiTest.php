<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class StatutoryInvoiceCancellationAdminUiTest extends TestCase
{
    use RefreshDatabase;

    private User $authorizedAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.irn_cancellation_window_hours' => 24,
        ]);

        $this->authorizedAdmin = User::factory()->create([
            'is_active' => true,
            'name' => 'Avinash Jha',
        ]);
        $this->authorizedAdmin->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_CANCEL);
        $this->authorizedAdmin->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW);
        $this->authorizedAdmin->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_VIEW);
    }

    public function test_authorized_user_sees_cancel_action_when_eligible(): void
    {
        $invoice = $this->issueB2bInvoice();

        $this->actingAs($this->authorizedAdmin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Cancel invoice', false)
            ->assertSee('Statutory cancellation policy', false);
    }

    public function test_unauthenticated_user_cannot_post_cancel(): void
    {
        $invoice = $this->issueB2bInvoice();

        $this->post(route('finance.invoices.cancel', $invoice), [
            'reason' => 'Should fail',
            'confirm' => '1',
        ])->assertRedirect();
    }

    public function test_unauthorized_user_cannot_post_cancel_even_with_display_name_admin(): void
    {
        $invoice = $this->issueB2bInvoice();

        $dileep = User::factory()->create(['name' => 'Dileep Admin', 'is_active' => true]);
        $dileep->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($dileep)
            ->post(route('finance.invoices.cancel', $invoice), [
                'reason' => 'Should be forbidden',
                'confirm' => '1',
            ])
            ->assertForbidden();
    }

    public function test_display_names_avinash_dileep_shipra_do_not_auto_grant_cancel_permission(): void
    {
        $invoice = $this->issueB2bInvoice();

        foreach (['Avinash Jha', 'Dileep Admin', 'Shipra Kumari'] as $name) {
            $user = User::factory()->create(['name' => $name, 'is_active' => true]);
            $user->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW);
            $user->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_VIEW);

            $this->actingAs($user)
                ->get(route('finance.invoices.show', $invoice))
                ->assertOk()
                ->assertDontSee('cancelInvoiceModal', false);
        }
    }

    public function test_manual_review_operation_hides_submit_and_shows_escalation(): void
    {
        $invoice = $this->issueB2bInvoice();
        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
            'actor_id' => $this->authorizedAdmin->id,
            'reason' => 'Stuck',
            'status' => StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
            'correlation_id' => 'corr-manual-test',
            'last_error' => 'limit reached',
            'attempt_count' => 3,
        ]);

        $this->actingAs($this->authorizedAdmin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Manual Finance/CA review required', false)
            ->assertSee('corr-manual-test', false)
            ->assertDontSee('cancelInvoiceModal', false);
    }

    public function test_unknown_irn_outcome_does_not_flash_false_success(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);

        $response = $this->actingAs($this->authorizedAdmin)
            ->from(route('finance.invoices.show', $invoice))
            ->post(route('finance.invoices.cancel', $invoice), [
                'reason' => 'Unknown provider outcome',
                'confirm' => '1',
            ]);

        $response->assertRedirect(route('finance.invoices.show', $invoice));
        $response->assertSessionHas('status_level', 'warning');
        $response->assertSessionHas('status', function (mixed $message): bool {
            $text = strtolower((string) $message);

            return ! str_contains($text, 'cancelled successfully')
                && str_contains($text, 'does not mean the irn is cancelled');
        });
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);

        $this->actingAs($this->authorizedAdmin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertSee('Reconciliation required', false)
            ->assertSee('2154', false);
    }

    public function test_get_irn_2154_does_not_show_cancelled_success(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::irnNotFound('fake', ['error_code' => '2154']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);
        $key = StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id;

        $this->actingAs($this->authorizedAdmin)
            ->post(route('finance.invoices.cancel', $invoice), ['reason' => 'First', 'confirm' => '1']);

        $this->actingAs($this->authorizedAdmin)
            ->post(route('finance.invoices.cancel', $invoice), ['reason' => 'Reconcile', 'confirm' => '1'])
            ->assertSessionHasErrors();

        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
    }

    public function test_duplicate_submit_is_idempotent_on_completed_cancel(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);

        $payload = ['reason' => 'Once', 'confirm' => '1'];

        $this->actingAs($this->authorizedAdmin)
            ->post(route('finance.invoices.cancel', $invoice), $payload)
            ->assertSessionHas('status_level', 'success');

        $this->actingAs($this->authorizedAdmin)
            ->post(route('finance.invoices.cancel', $invoice->fresh()), $payload)
            ->assertSessionHas('status', fn ($message) => str_contains((string) $message, 'already cancelled'));

        $this->assertSame(1, $fake->cancelCount);
    }

    private function issueB2bInvoice(): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-UI-'.uniqid(),
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => \App\Enums\StatutoryInvoiceChannel::DeskPos,
            'source_type' => \App\Enums\StatutoryInvoiceSourceType::InventorySale,
            'source_id' => 'SALE-UI',
            'idempotency_key' => 'test:ui:'.uniqid(),
            'buyer_gstin' => '07AABCU9603R1ZM',
            'buyer_name' => 'B2B Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'invoice_value' => 118,
            'tax_total' => 18,
            'issued_at' => now(),
        ]);
    }

    private function attachIrn(StatutoryInvoice $invoice): void
    {
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'irn' => str_repeat('a', 64),
            'ack_no' => 'ACK-1',
            'ack_date' => now()->subHours(2),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
    }
}
