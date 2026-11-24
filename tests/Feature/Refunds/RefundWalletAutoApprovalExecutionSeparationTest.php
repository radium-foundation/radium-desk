<?php

namespace Tests\Feature\Refunds;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Events\Finance\RefundCompleted;
use App\Models\AuditLog;
use App\Models\RefundRequest;
use App\Notifications\RefundRequestDecisionNotification;
use App\Services\RefundRequestService;
use App\Services\Refunds\RefundExecutorResolver;
use App\Services\Refunds\RefundWalletAutoApprovalEvaluator;
use App\Services\Refunds\WalletRefundExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Refunds\Support\WalletAutoApprovalTestCase;

class RefundWalletAutoApprovalExecutionSeparationTest extends WalletAutoApprovalTestCase
{
    public function test_successful_auto_approval_does_not_invoke_executor_resolver_or_wallet_executor(): void
    {
        $this->enablePilotConfig();
        Notification::fake();
        Event::fake([RefundCompleted::class]);
        Queue::fake();

        $executorResolver = $this->mock(RefundExecutorResolver::class);
        $executorResolver->shouldNotReceive('for');

        $walletExecutor = $this->mock(WalletRefundExecutor::class);
        $walletExecutor->shouldNotReceive('execute');

        [$agent, $order] = $this->bindOrder('RD3438201', '1200.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertSame(ApprovedRefundMethod::Wallet, $refund->approved_refund_method);
        Event::assertNotDispatched(RefundCompleted::class);
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertNull($refund->executed_at);
    }

    public function test_auto_approval_does_not_dispatch_customer_completion_notifications(): void
    {
        $this->enablePilotConfig();
        Notification::fake();

        [$agent, $order] = $this->bindOrder('RD3438202', '1100.00');

        app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        Notification::assertNotSentTo($agent, RefundRequestDecisionNotification::class);
    }

    public function test_auto_approval_writes_single_auto_attributed_audit_without_completion_events(): void
    {
        $this->enablePilotConfig();
        Notification::fake();

        [$agent, $order] = $this->bindOrder('RD3438203', '1300.00');

        $refund = app(RefundRequestService::class)->create(
            $agent,
            $this->fullRefundPayload($order),
            Request::create('/'),
        );

        $approvalAudits = AuditLog::query()
            ->where('auditable_type', RefundRequest::class)
            ->where('auditable_id', $refund->id)
            ->where('event', 'refund.approved')
            ->get();

        $this->assertCount(1, $approvalAudits);
        $this->assertSame('auto', $approvalAudits->first()->new_values['approval_source'] ?? null);
        $this->assertSame(
            RefundWalletAutoApprovalEvaluator::REVIEW_NOTES,
            $refund->fresh()->review_notes,
        );

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('auditable_id', $refund->id)
                ->where('event', 'refund.completed')
                ->count(),
        );
    }

    public function test_transaction_rollback_leaves_no_partial_auto_approval_state(): void
    {
        $this->enablePilotConfig();

        [$agent, $order] = $this->bindOrder('RD3438204', '1000.00');

        $service = app(RefundRequestService::class);

        try {
            DB::transaction(function () use ($service, $agent, $order): void {
                $service->create($agent, $this->fullRefundPayload($order), Request::create('/'));
                throw new \RuntimeException('Forced rollback after create for isolation probe.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced rollback after create for isolation probe.', $exception->getMessage());
        }

        $this->assertSame(0, RefundRequest::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('event', 'refund.approved')
                ->where('new_values->approval_source', 'auto')
                ->count(),
        );
    }
}
