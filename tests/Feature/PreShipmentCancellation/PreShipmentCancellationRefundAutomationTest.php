<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\CommerceOrderStatus;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\RefundStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Jobs\PreShipmentCancellation\ProcessPreShipmentCancellationRefundAutomationJob;
use App\Jobs\Refunds\RefundCompletionFollowUpJob;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationRefundAutomationService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use App\Services\RefundRequestService;
use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use App\Support\PreShipmentCancellation\PreShipmentCancellationRefundOperatorState;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class PreShipmentCancellationRefundAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_WALLET_REFUNDS_URL = 'https://radiumbox.test/api/integrations/v1/wallet-refunds';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        config([
            'pre_shipment_cancellation.enabled' => true,
            'refunds.wallet_auto_approval.enabled' => true,
            'refunds.wallet_auto_approval.spokes' => 'radiumbox.com',
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'radiumbox.com',
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'lookup-token',
            'order_lookup.spokes.radiumbox_com.accepts' => ['rd', 'rde', 'rb', 'rbp', 'rbx'],
        ]);
    }

    /**
     * @return array{0: User, 1: Order, 2: CommerceOrder}
     */
    private function walletAutomationFixtures(float $amount = 599.00): array
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $orderId = 'RBP'.random_int(910000, 919999);
        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'serial_number' => null,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'cashfree_payment_id' => 'cf_test_'.$orderId,
            'customer_email' => 'psc-wallet-auto@example.com',
            'created_by' => $actor->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'psc-'.$order->id,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => $amount,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $branch = InventoryBranch::query()->create([
            'code' => 'BR-PSC-'.random_int(100, 999),
            'name' => 'PSC Branch',
            'is_active' => true,
        ]);
        HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $branch->id,
            'ingested_at' => now(),
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-PSC-'.random_int(1000, 9999),
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Hardware fulfilment',
            'description' => 'Awaiting serial',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        return [$actor, $order, $commerce];
    }

    private function runAutomation(PreShipmentCancellationRequest $case, User $actor): void
    {
        app(PreShipmentCancellationRefundAutomationService::class)->process($case->id, $actor->id);
    }

    private function fakeRadiumBoxWalletEnvironment(string $orderId, string $credit = '599.00'): void
    {
        $lookupUrl = 'https://radiumbox.test/api/integrations/v1/rd-orders/'.$orderId;
        $lookupPayload = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/radiumbox-order-lookup-rb1321-ref67458.json')),
            true,
        );
        $this->assertIsArray($lookupPayload);

        Http::fake(function ($request) use ($lookupUrl, $lookupPayload, $credit) {
            if ($request->url() === $lookupUrl) {
                return Http::response($lookupPayload, 200);
            }

            if (str_ends_with($request->url(), '/wallet-refunds')) {
                return Http::response([
                    'status' => 201,
                    'message' => 'Central Wallet audit recorded',
                    'data' => [
                        'wallet_transaction_id' => 501,
                        'wallet_reference' => 'CW:501',
                        'desk_refund_reference' => (string) ($request['desk_refund_reference'] ?? ''),
                        'source_system' => 'radium_desk',
                        'userid' => 551444,
                        'credit' => $credit,
                        'currency' => 'INR',
                        'balance' => $credit,
                        'destination' => 'central_wallet',
                        'local_spendable_balance' => '0.00',
                    ],
                ], 201);
            }

            return Http::response([], 404);
        });
    }

    public function test_submit_dispatches_refund_automation_job(): void
    {
        Bus::fake([ProcessPreShipmentCancellationRefundAutomationJob::class]);

        [$actor, $order] = $this->walletAutomationFixtures();
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Customer cancel before ship.',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', null),
            'idempotency_key' => 'psc-auto-dispatch-'.$order->id,
        ]);

        $this->assertSame(PreShipmentCancellationStatus::FinanceApproved, $case->status);
        Bus::assertDispatched(ProcessPreShipmentCancellationRefundAutomationJob::class, function ($job) use ($case, $actor): bool {
            return $job->preShipmentCancellationRequestId === $case->id
                && $job->actorUserId === $actor->id;
        });
    }

    public function test_wallet_preference_with_flags_links_approves_and_completes_without_customer_follow_up(): void
    {
        Bus::fake([RefundCompletionFollowUpJob::class]);

        [$actor, $order] = $this->walletAutomationFixtures();
        $this->fakeRadiumBoxWalletEnvironment((string) $order->order_id);
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Wallet refund automation.',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', null),
            'idempotency_key' => 'psc-wallet-full-'.$order->id,
        ]);

        $this->runAutomation($case, $actor);

        $case = $case->fresh();
        $this->assertNotNull($case->refund_request_id);
        $this->assertSame(PreShipmentCancellationStatus::Completed, $case->status);
        $this->assertSame(1, RefundRequest::query()->count());

        $refund = RefundRequest::query()->findOrFail($case->refund_request_id);
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
        $this->assertSame(CustomerPreferredRefundMethod::Wallet, $refund->customer_preferred_method);
        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());
        $this->assertSame(599.00, (float) $case->already_refunded_amount);

        $state = PreShipmentCancellationRefundOperatorState::resolve($case->fresh()->load('refundRequest'));
        $this->assertSame(PreShipmentCancellationRefundOperatorState::CREDITED_TO_WALLET, $state['key']);

        Bus::assertNotDispatched(RefundCompletionFollowUpJob::class);
    }

    public function test_opm_preference_links_only_without_auto_execution(): void
    {
        Bus::fake([RefundCompletionFollowUpJob::class]);

        [$actor, $order] = $this->walletAutomationFixtures();
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'OPM refund path.',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('opm', null),
            'idempotency_key' => 'psc-opm-link-'.$order->id,
        ]);

        $this->runAutomation($case, $actor);

        $refund = RefundRequest::query()->firstOrFail();
        $this->assertSame(CustomerPreferredRefundMethod::Opm, $refund->customer_preferred_method);
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        Bus::assertNotDispatched(RefundCompletionFollowUpJob::class);

        $state = PreShipmentCancellationRefundOperatorState::resolve($case->fresh()->load('refundRequest'));
        $this->assertSame(PreShipmentCancellationRefundOperatorState::PENDING_FINANCE, $state['key']);
    }

    public function test_repeated_automation_is_idempotent(): void
    {
        [$actor, $order] = $this->walletAutomationFixtures();
        $this->fakeRadiumBoxWalletEnvironment((string) $order->order_id);
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Idempotent automation.',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', null),
            'idempotency_key' => 'psc-idem-'.$order->id,
        ]);

        $automation = app(PreShipmentCancellationRefundAutomationService::class);
        $automation->process($case->id, $actor->id);
        $automation->process($case->id, $actor->id);

        $this->assertSame(1, RefundRequest::query()->count());
        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());
    }

    public function test_wallet_flags_disabled_leaves_linked_request_pending_finance(): void
    {
        config(['refunds.wallet_auto_approval.enabled' => false]);

        [$actor, $order] = $this->walletAutomationFixtures();
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Wallet preference but flags off.',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', null),
            'idempotency_key' => 'psc-flags-off-'.$order->id,
        ]);

        $this->runAutomation($case, $actor);

        $refund = RefundRequest::query()->firstOrFail();
        $this->assertContains($refund->status, [RefundStatus::Pending, RefundStatus::PendingExecution]);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_link_failure_preserves_committed_cancellation(): void
    {
        [$actor, $order] = $this->walletAutomationFixtures();
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Link failure path.',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('opm', null),
            'idempotency_key' => 'psc-link-fail-'.$order->id,
        ]);

        $mock = Mockery::mock(RefundRequestService::class)->makePartial();
        $mock->shouldReceive('create')->once()->andThrow(ValidationException::withMessages([
            'refund' => 'Simulated link failure.',
        ]));
        $this->app->instance(RefundRequestService::class, $mock);

        $this->runAutomation($case, $actor);

        $case = $case->fresh();
        $this->assertSame(PreShipmentCancellationStatus::FinanceApproved, $case->status);
        $this->assertNull($case->refund_request_id);
        $this->assertSame('refund_auto_link_failed', $case->sub_status);
        $this->assertSame(0, RefundRequest::query()->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
