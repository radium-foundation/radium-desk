<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationPolicyActionType;
use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutorySupplyKind;
use App\Enums\WaitingReason;
use App\Events\Finance\OrderPaid;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use App\Services\ServiceCaseStatusService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceStatutoryIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private StatutoryBillingIssuer $issuer;

    private StatutoryMintEligibility $eligibility;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 18:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->issuer = app(StatutoryBillingIssuer::class);
        $this->eligibility = app(StatutoryMintEligibility::class);
        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'superadmin@radium.local']);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_maharashtra_b2c_selects_mumbai_inv_27671(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-MH-B2C', billingState: 'Maharashtra'),
            $this->actor,
        );

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('statutory:rdservice_in:commerce_order:RD-MH-B2C', $invoice->idempotency_key);
    }

    public function test_non_maharashtra_b2c_selects_delhi_b2c_inv_671_not_07671(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-DL-B2C', billingState: 'Karnataka'),
            $this->actor,
        );

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertNotSame('INV-07671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertSame(StatutoryLocationSeries::DELHI_B2C, $invoice->allocation?->sequence?->gstin_scope
            ? substr((string) $invoice->allocation->sequence->gstin_scope, strlen('location:'))
            : null);
    }

    public function test_maharashtra_b2b_selects_shared_mumbai_sequence(): void
    {
        $b2c = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-MH-B2C-SHARE', billingState: 'Maharashtra'),
            $this->actor,
        );
        $b2b = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-MH-B2B', buyerGstin: '27AAAAA0000A1Z5', billingState: 'Delhi'),
            $this->actor,
        );

        $this->assertSame('INV-27671', $b2c->invoice_number);
        $this->assertSame('INV-27672', $b2b->invoice_number);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $b2b->seller_gstin);
        $this->assertSame('27AAAAA0000A1Z5', $b2b->buyer_gstin);
    }

    public function test_non_maharashtra_b2b_selects_delhi_b2b_inv_07671(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-DL-B2B', buyerGstin: '07AAAAA0000A1Z5', billingState: 'Maharashtra'),
            $this->actor,
        );

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
    }

    public function test_invalid_non_empty_gstin_fails_closed_without_invoice(): void
    {
        $order = $this->commerceOrder('RD-BAD-GSTIN', buyerGstin: '27-NOT-A-GSTIN', billingState: 'Maharashtra');

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected invalid GSTIN to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_missing_b2c_billing_state_fails_closed_without_invoice(): void
    {
        $order = $this->commerceOrder('RD-NO-STATE', billingState: null);

        $this->assertFalse($this->eligibility->evaluateOrder($order)->eligible);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected missing billing_state to fail closed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_invalid_b2c_billing_state_fails_closed_without_invoice(): void
    {
        $order = $this->commerceOrder('RD-BAD-STATE', billingState: 'Bombay');

        $this->assertFalse($this->eligibility->evaluateOrder($order)->eligible);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected invalid billing_state to fail closed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_b2b_issuer_follows_gstin_not_billing_state(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->require(StatutorySupplyKind::Service, 'DELHI-RETAIL', '27AAAAA0000A1Z5', 'Karnataka'),
        );

        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-GSTIN-WINS', buyerGstin: '27AAAAA0000A1Z5', billingState: 'Karnataka'),
            $this->actor,
        );

        $this->assertSame('INV-27671', $invoice->invoice_number);
    }

    public function test_place_of_supply_cannot_substitute_for_missing_b2c_billing_state(): void
    {
        $order = $this->commerceOrder('RD-POS-ONLY', billingState: null, placeOfSupply: 'Maharashtra');

        $this->assertSame('Maharashtra', $order->place_of_supply_state);
        $this->assertNull($order->billing_state);
        $this->assertFalse($this->eligibility->evaluateOrder($order)->eligible);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected place of supply not to substitute for billing_state.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('billing_state', strtolower(implode(' ', $this->flattenErrors($exception))));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_rd3512358_shaped_incomplete_tax_classifies_but_does_not_mint(): void
    {
        $order = $this->rd3512358ShapedOrder();

        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->requireForCommerceOrder(
                $order->branch_code,
                $order->buyer_gstin,
                $order->billing_state,
                $order->items->pluck('hsn_sac')->all(),
            ),
        );
        $this->assertSame(
            'INV-27671',
            app(StatutoryLocationSeries::class)->formatNumber(
                StatutoryLocationSeries::MUMBAI,
                StatutoryFinancialYear::fromToken('2026-2027'),
                1,
            ),
        );

        $decision = $this->eligibility->evaluateOrder($order);
        $this->assertFalse($decision->eligible);
        $this->assertTrue(collect($decision->errors)->contains(
            fn (string $error): bool => str_contains(strtolower($error), 'gst')
                || str_contains(strtolower($error), 'statutory amounts'),
        ));

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected incomplete tax data to refuse mint.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
        $this->assertSame(0, InvoiceSequence::query()->count());
        $this->assertNull($order->fresh()->statutory_invoice_id);
    }

    public function test_reference_number_trigger_issues_after_successful_commit(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512401', billingState: 'Delhi');

        $assigned = app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-SVC-REF-1',
            $this->actor,
            broadcast: false,
        );

        $this->assertSame('TXN-SVC-REF-1', $assigned->transaction_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('INV-671', StatutoryInvoice::query()->value('invoice_number'));
        $this->assertSame('statutory:rdservice_in:commerce_order:RD3512401', StatutoryInvoice::query()->value('idempotency_key'));
        $this->assertSame($assigned->id, (int) StatutoryInvoice::query()->value('support_order_id'));
    }

    public function test_reference_trigger_does_not_roll_back_assignment_when_issuance_fails(): void
    {
        $order = $this->deskOrder('RD-REF-NO-COMMERCE');

        Log::spy();

        $assigned = app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-SVC-REF-FAIL',
            $this->actor,
            broadcast: false,
        );

        $this->assertSame('TXN-SVC-REF-FAIL', $assigned->transaction_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_no_response_closure_trigger_issues_after_successful_commit(): void
    {
        [$order, $incident] = $this->deskOrderWithCommerce('RD3512402', billingState: 'Maharashtra');
        $waiting = $this->waitingReadyToAutoClose($incident);

        $result = app(CustomerWaitingLifecycleService::class)->autoCloseForNoResponse(
            $this->autoCloseAction($waiting),
        );

        $this->assertTrue($result->success);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('INV-27671', StatutoryInvoice::query()->value('invoice_number'));
        $this->assertSame($order->id, (int) StatutoryInvoice::query()->value('support_order_id'));
    }

    public function test_generic_case_closure_does_not_issue(): void
    {
        [$order, $incident] = $this->deskOrderWithCommerce('RD-GENERIC-CLOSE', billingState: 'Delhi');

        app(ServiceCaseStatusService::class)->updateStatus($incident, IncidentStatus::Closed, $this->actor);

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertNull($order->fresh()->transaction_id);
    }

    public function test_payment_success_does_not_issue(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD-PAID-ONLY', billingState: 'Delhi');

        OrderPaid::dispatch($order);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_duplicate_reference_trigger_issues_one_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD-REF-DUP', billingState: 'Delhi');

        app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-DUP-1', $this->actor, broadcast: false);

        try {
            app(OrderTransactionService::class)->assignTransactionId($order->fresh(), 'TXN-DUP-1', $this->actor, broadcast: false);
        } catch (ValidationException) {
            // Locked orders with a different/same path may refuse a second assign.
        }

        $this->invoices->issueFromSupportOrder($order->fresh(), $this->actor);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
        $this->assertSame('INV-671', StatutoryInvoice::query()->value('invoice_number'));
    }

    public function test_duplicate_waiting_close_trigger_issues_one_invoice(): void
    {
        [, $incident] = $this->deskOrderWithCommerce('RD-WAIT-DUP', billingState: 'Karnataka');
        $waiting = $this->waitingReadyToAutoClose($incident);
        $action = $this->autoCloseAction($waiting);

        $first = app(CustomerWaitingLifecycleService::class)->autoCloseForNoResponse($action);
        app(CustomerWaitingLifecycleService::class)->autoCloseForNoResponse(
            $this->autoCloseAction($waiting->fresh()),
        );
        $this->invoices->issueFromSupportOrder(
            Order::query()->where('order_id', 'RD-WAIT-DUP')->firstOrFail(),
            $this->actor,
        );

        $this->assertTrue($first->success);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
    }

    public function test_reference_and_waiting_close_converge_on_one_invoice(): void
    {
        [$order, $incident] = $this->deskOrderWithCommerce('RD-RACE-TRIGGERS', billingState: 'Delhi');
        $waiting = $this->waitingReadyToAutoClose($incident);

        app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-RACE-1', $this->actor, broadcast: false);
        app(CustomerWaitingLifecycleService::class)->autoCloseForNoResponse(
            $this->autoCloseAction($waiting->fresh()),
        );

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
        $this->assertSame('statutory:rdservice_in:commerce_order:RD-RACE-TRIGGERS', StatutoryInvoice::query()->value('idempotency_key'));
    }

    public function test_finance_hub_and_workflow_trigger_race_issues_one_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD-RACE-HUB', billingState: 'Maharashtra');
        $commerce = CommerceOrder::query()->where('source_id', 'RD-RACE-HUB')->firstOrFail();

        $fromHub = $this->invoices->issueFromCommerceOrder($commerce, $this->actor);
        $fromSupport = $this->invoices->issueFromSupportOrder($order, $this->actor);
        app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-HUB-RACE', $this->actor, broadcast: false);

        $this->assertSame($fromHub->id, $fromSupport->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
        $this->assertSame('INV-27671', $fromHub->invoice_number);
        $this->assertSame(StatutoryInvoiceSourceType::CommerceOrder->value, $fromHub->source_type);
    }

    public function test_existing_legacy_invoice_remains_untouched(): void
    {
        $legacy = $this->invoices->mint(new StatutoryInvoiceMintRequest(
            channel: StatutoryInvoiceChannel::DeskPos,
            sourceType: StatutoryInvoiceSourceType::External,
            sourceId: 'legacy-inv6717810',
            lines: [new StatutoryInvoiceLineDraft(
                description: 'Historical line',
                qty: 1,
                unitPrice: 10,
                gstPercentage: 18,
                taxTotal: 1.8,
                lineTotal: 11.8,
                taxableValue: 10,
                hsnSac: '8471',
            )],
            numberingLocation: StatutoryLocationSeries::DELHI,
            financialYearToken: '2026-2027',
        ), $this->actor);

        $this->assertSame('INV-07671', $legacy->invoice_number);

        $service = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-AFTER-LEGACY', billingState: 'Delhi'),
            $this->actor,
        );

        $this->assertSame('INV-671', $service->invoice_number);
        $this->assertSame('INV-07671', $legacy->fresh()->invoice_number);
        $this->assertSame('legacy-inv6717810', $legacy->fresh()->source_id);
        $this->assertSame(2, StatutoryInvoice::query()->count());
    }

    public function test_fy_2027_28_delhi_b2c_fails_closed_without_inventing_inv_781(): void
    {
        $order = $this->commerceOrder('RD-FY27-B2C', billingState: 'Delhi', orderedAt: '2027-04-01 10:00:00');

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected FY 2027-28 Delhi B2C to fail closed.');
        } catch (ValidationException $exception) {
            $flat = implode(' ', $this->flattenErrors($exception));
            $this->assertStringContainsString('UNKNOWN', $flat);
            $this->assertStringNotContainsString('INV-781', $flat);
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_delhi_b2c_delhi_b2b_and_mumbai_sequences_are_isolated(): void
    {
        $delhiB2c = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-ISO-B2C', billingState: 'Delhi'),
            $this->actor,
        );
        $delhiB2b = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-ISO-B2B', buyerGstin: '29AAAAA0000A1Z5'),
            $this->actor,
        );
        $mumbai = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-ISO-MH', billingState: 'Maharashtra'),
            $this->actor,
        );

        $this->assertSame('INV-671', $delhiB2c->invoice_number);
        $this->assertSame('INV-07671', $delhiB2b->invoice_number);
        $this->assertSame('INV-27671', $mumbai->invoice_number);
        $this->assertSame(3, InvoiceSequence::query()->count());
        $this->assertSame(3, InvoiceSequenceAllocation::query()->count());
        $this->assertNotSame($delhiB2c->allocation?->sequence_id, $delhiB2b->allocation?->sequence_id);
        $this->assertNotSame($delhiB2c->allocation?->sequence_id, $mumbai->allocation?->sequence_id);
        $this->assertNotSame($delhiB2b->allocation?->sequence_id, $mumbai->allocation?->sequence_id);
    }

    public function test_retry_keeps_the_same_statutory_identity(): void
    {
        $order = $this->commerceOrder('RD-RETRY', billingState: 'Delhi');

        $first = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $second = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);
        $third = $this->invoices->issueFromSupportOrder($this->deskOrder('RD-RETRY'), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame('INV-671', $first->invoice_number);
        $this->assertSame('statutory:rdservice_in:commerce_order:RD-RETRY', $first->idempotency_key);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
    }

    public function test_support_order_does_not_mint_a_second_source_identity(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3512403', billingState: 'Delhi');

        $invoice = $this->invoices->issueFromSupportOrder($order, $this->actor);

        $this->assertSame(StatutoryInvoiceSourceType::CommerceOrder->value, $invoice->source_type);
        $this->assertSame('RD3512403', $invoice->source_id);
        $this->assertSame('statutory:rdservice_in:commerce_order:RD3512403', $invoice->idempotency_key);
        $this->assertSame(0, StatutoryInvoice::query()->where('source_type', StatutoryInvoiceSourceType::SupportOrder->value)->count());
        $this->assertSame($order->id, (int) $invoice->support_order_id);
        $this->assertSame($order->id, (int) CommerceOrder::query()->where('source_id', 'RD3512403')->value('support_order_id'));
    }

    public function test_auto_flags_remain_off(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('channel_ingest.auto_issue_invoice'));
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function deskOrderWithCommerce(
        string $orderId,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
    ): array {
        $order = $this->deskOrder($orderId);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Service issuance case',
            'description' => 'Service issuance case.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->commerceOrder($orderId, billingState: $billingState, buyerGstin: $buyerGstin);

        return [$order->fresh(), $incident];
    }

    private function deskOrder(string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_'.$orderId,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function waitingReadyToAutoClose(Incident $incident): IncidentWaitingState
    {
        return IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::Photos,
            'started_at' => Carbon::parse('2026-09-01 10:00:00'),
            'customer_followup_sent_at' => Carbon::parse('2026-09-01 10:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function autoCloseAction(IncidentWaitingState $waiting): PlannedAutomationAction
    {
        return new PlannedAutomationAction(
            waitingState: $waiting->fresh(['incident.order', 'incident.supportAppointments']),
            policyKey: 'customer_waiting_default',
            scheduleStep: 1,
            actionType: AutomationPolicyActionType::AutoClose,
            actionKey: 'customer_not_responding',
            channel: null,
            scheduledAt: now(),
        );
    }

    private function commerceOrder(
        string $sourceId,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
        string $placeOfSupply = 'Delhi',
        string $orderedAt = '2026-09-01 10:00:00',
        ?float $gstPercentage = 18.0,
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'buyer_gstin' => $buyerGstin,
            'billing_state' => $billingState,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $placeOfSupply,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => $orderedAt,
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => $gstPercentage,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]);

        return $order->fresh(['items']);
    }

    private function rd3512358ShapedOrder(): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-000001',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RD3512358',
            'source_order_id' => 'RD3512358',
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:RD3512358',
            'payload_hash' => hash('sha256', 'RD3512358-shape'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => false,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'RD3512358 Customer',
            'buyer_gstin' => null,
            'billing_state' => 'Maharashtra',
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => '2026-09-06 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'RD Service',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => null,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]);
        $order->items()->create([
            'line_no' => 2,
            'description' => 'Zero companion',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        return $order->fresh(['items']);
    }

    /**
     * @return list<string>
     */
    private function flattenErrors(ValidationException $exception): array
    {
        $flat = [];
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $flat[] = (string) $message;
            }
        }

        return $flat;
    }
}
