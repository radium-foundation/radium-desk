<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\PosReturnRefundRequestStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\PosReturnRefundRequest;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\PosReturnRefundRequestService;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosReturnRefundRequestTest extends ReturnExchangeCaseHttpTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_inspected_pos_case_with_cancellation_submits_single_request(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();

        $this->actingAs($branchAdmin)
            ->post(route('return-cases.pos-refund-request.store', $case), [
                'requester_remarks' => 'Please review',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $this->assertSame(1, PosReturnRefundRequest::query()->count());
        $request = $case->fresh()->posRefundRequest;
        $this->assertNotNull($request);
        $this->assertSame(PosReturnRefundRequestStatus::PendingFinanceReview, $request->status);
        $this->assertStringStartsWith('PRR-', $request->reference_no);
        $this->assertGreaterThan(0, (float) $request->amount);
        $this->assertSame('Cash', $request->payment_method);
    }

    public function test_missing_tax_evidence_blocks_submission(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithInvoiceOnly();

        $this->actingAs($branchAdmin)
            ->post(route('return-cases.pos-refund-request.store', $case))
            ->assertSessionHasErrors('tax_evidence');

        $this->assertSame(0, PosReturnRefundRequest::query()->count());
    }

    public function test_uninspected_case_blocks_submission(): void
    {
        [$case, $branchAdmin, $invoice] = $this->posCaseReadyForReceipt();
        $this->attachCancellation($invoice, $branchAdmin);

        $this->actingAs($branchAdmin)
            ->post(route('return-cases.pos-refund-request.store', $case))
            ->assertSessionHasErrors('case');

        $this->assertSame(0, PosReturnRefundRequest::query()->count());
    }

    public function test_duplicate_submission_is_blocked(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();
        $service = app(PosReturnRefundRequestService::class);
        $service->submit($branchAdmin, $case);

        $this->actingAs($branchAdmin)
            ->post(route('return-cases.pos-refund-request.store', $case))
            ->assertSessionHasErrors('refund_request');

        $this->assertSame(1, PosReturnRefundRequest::query()->count());
    }

    public function test_service_blocks_concurrent_duplicate_submit(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();
        $service = app(PosReturnRefundRequestService::class);
        $service->submit($branchAdmin, $case);

        $this->expectException(ValidationException::class);
        $service->submit($branchAdmin, $case->fresh());
    }

    public function test_partial_return_case_is_blocked(): void
    {
        $branchAdmin = $this->branchAdminFor($this->branchA->id);
        $product = $this->createProduct('PART-1', false);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branchA, 2, $branchAdmin);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900333'],
            lines: [['product_id' => $product->id, 'qty' => 2]],
            paymentMethod: 'Cash',
            actor: $branchAdmin,
        );
        $invoice = $this->createPosInvoice($sale, 'INV-PART-1');
        $this->attachCancellation($invoice, $branchAdmin);
        $saleLineId = $sale->lines()->value('id');

        $case = app(ReturnExchangeCaseIntakeService::class)->create($branchAdmin, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'branch_id' => $this->branchA->id,
            'reason' => 'Partial',
            'lines' => [[
                'description' => 'One unit',
                'qty' => 1,
                'inventory_sale_line_id' => $saleLineId,
            ]],
        ]);

        $this->receiveAndInspectNonSerial($case, $branchAdmin, qty: 1);

        $this->expectException(ValidationException::class);
        app(PosReturnRefundRequestService::class)->submit($branchAdmin, $case->fresh());
    }

    public function test_agent_cannot_submit_pos_refund_request(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();
        $agent = $this->agentUser();
        InventoryUserBranch::query()->create([
            'user_id' => $agent->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($agent)
            ->post(route('return-cases.pos-refund-request.store', $case))
            ->assertForbidden();

        $this->assertSame(0, PosReturnRefundRequest::query()->count());
    }

    public function test_cross_branch_admin_cannot_submit(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();
        $otherAdmin = $this->branchAdminFor($this->branchB->id);

        $this->actingAs($otherAdmin)
            ->post(route('return-cases.pos-refund-request.store', $case))
            ->assertForbidden();
    }

    public function test_central_finance_can_approve_and_reject_without_payout(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();
        app(PosReturnRefundRequestService::class)->submit($branchAdmin, $case);
        $request = $case->fresh()->posRefundRequest;
        $central = $this->centralAdmin();

        $this->actingAs($central)
            ->post(route('pos-return-refund-requests.review', $request), [
                'decision' => 'approve',
                'review_reason' => 'OK for later payout',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $request->refresh();
        $this->assertSame(PosReturnRefundRequestStatus::Approved, $request->status);
        $this->assertNotNull($request->reviewed_at);

        [$case2, $branchAdmin2] = $this->fullyInspectedPosCaseWithTaxCancellation('PRR-PROD-B', 'INV-PRR-B');
        app(PosReturnRefundRequestService::class)->submit($branchAdmin2, $case2);
        $rejectTarget = $case2->fresh()->posRefundRequest;

        $this->actingAs($central)
            ->post(route('pos-return-refund-requests.review', $rejectTarget), [
                'decision' => 'reject',
                'review_reason' => 'Insufficient documentation',
            ])
            ->assertRedirect(route('return-cases.show', $case2));

        $this->assertSame(PosReturnRefundRequestStatus::Rejected, $rejectTarget->fresh()->status);
    }

    public function test_non_central_user_cannot_review(): void
    {
        [$case, $branchAdmin] = $this->fullyInspectedPosCaseWithTaxCancellation();
        app(PosReturnRefundRequestService::class)->submit($branchAdmin, $case);
        $request = $case->fresh()->posRefundRequest;
        $agent = $this->agentUser();

        $this->actingAs($agent)
            ->post(route('pos-return-refund-requests.review', $request), [
                'decision' => 'approve',
            ])
            ->assertForbidden();
    }

    public function test_no_execute_route_records_payout(): void
    {
        $this->assertFalse(
            collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                fn ($route): bool => str_contains((string) $route->getName(), 'pos-return-refund')
                    && str_contains((string) $route->getName(), 'complete'),
            ),
        );
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User}
     */
    private function fullyInspectedPosCaseWithTaxCancellation(
        string $sku = 'PRR-PROD',
        string $invoiceNumber = 'INV-PRR-001',
    ): array {
        [$case, $admin, $invoice] = $this->posCaseReadyForReceipt($sku, $invoiceNumber);
        $this->attachCancellation($invoice, $admin);
        $this->receiveAndInspectNonSerial($case, $admin);

        return [$case->fresh(), $admin];
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User}
     */
    private function fullyInspectedPosCaseWithInvoiceOnly(): array
    {
        [$case, $admin] = $this->posCaseReadyForReceipt();
        $this->receiveAndInspectNonSerial($case, $admin);

        return [$case->fresh(), $admin];
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: StatutoryInvoice}
     */
    private function posCaseReadyForReceipt(string $sku = 'PRR-PROD', string $invoiceNumber = 'INV-PRR-001'): array
    {
        $admin = $this->branchAdminFor($this->branchA->id);
        $product = $this->createProduct($sku, false);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branchA, 1, $admin);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900444'],
            lines: [['product_id' => $product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $admin,
        );
        $invoice = $this->createPosInvoice($sale, $invoiceNumber);
        $sale->update(['statutory_invoice_id' => $invoice->id]);

        $case = app(ReturnExchangeCaseIntakeService::class)->create($admin, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'branch_id' => $this->branchA->id,
            'reason' => 'POS refund test',
            'lines' => [[
                'description' => $product->name,
                'qty' => 1,
                'inventory_sale_line_id' => $sale->lines()->value('id'),
            ]],
        ]);

        return [$case, $admin, $invoice];
    }

    private function receiveAndInspectNonSerial(\App\Models\ReturnExchangeCase $case, User $receiver, int $qty = 1): void
    {
        $line = $case->lines()->firstOrFail();
        $receipts = app(ReturnExchangeCaseGoodsReceiptService::class);
        $receipt = $receipts->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => $qty,
        ]);
        $receipts->inspect($case, $receipt, $receiver, [
            'product_match_passed' => true,
            'device_operates_ok' => true,
            'accessories_verified' => true,
        ]);
    }

    private function branchAdminFor(int $branchId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }

    private function createProduct(string $sku, bool $serialized): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => $serialized,
            'is_active' => true,
        ]);
    }

    private function createPosInvoice(\App\Models\InventorySale $sale, string $invoiceNumber): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'inventory_sale_id' => $sale->id,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'source_id' => (string) $sale->id,
            'idempotency_key' => 'prr-'.$invoiceNumber,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => 'Addr',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => '1000.00',
            'discount' => '0.00',
            'tax_total' => '180.00',
            'cgst' => '90.00',
            'sgst' => '90.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '1180.00',
            'issued_at' => now(),
        ]);
    }

    private function attachCancellation(StatutoryInvoice $invoice, User $actor): void
    {
        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => 'cancel-'.$invoice->id,
            'actor_id' => $actor->id,
            'reason' => 'Test cancellation',
            'completed_at' => now(),
        ]);
    }
}
