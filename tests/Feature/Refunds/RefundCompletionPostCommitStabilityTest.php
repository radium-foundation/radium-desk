<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\BusinessHoldType;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Jobs\Refunds\RefundCompletionFollowUpJob;
use App\Models\BusinessHold;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\Refunds\RefundCompletionFollowUpService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RefundCompletionPostCommitStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'cashfree.system_user_email' => 'ops@example.com',
            'interakt.templates.refund_confirmation.enabled' => false,
            'mail.enabled' => false,
            'notifications.email.enabled' => false,
        ]);
    }

    public function test_complete_defers_case_close_until_after_response(): void
    {
        Bus::fake([RefundCompletionFollowUpJob::class]);

        [$ops, $refund, $incident] = $this->pendingExecutionRefund();

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-DEFER-1',
                'execution_transaction_id' => 'TXN-DEFER-1',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        Bus::assertDispatched(RefundCompletionFollowUpJob::class, function (RefundCompletionFollowUpJob $job) use ($refund, $ops): bool {
            return $job->refundId === $refund->id && $job->actorUserId === $ops->id;
        });

        $refund->refresh();
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_follow_up_job_closes_linked_case_and_hold(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(withHold: true);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-DEFER-2',
                'execution_transaction_id' => 'TXN-DEFER-2',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $incident->refresh();

        $this->assertSame(RefundStatus::Closed, $refund->status);
        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
        $this->assertNotNull(BusinessHold::query()->where('incident_id', $incident->id)->value('cleared_at'));
    }

    public function test_financial_commit_survives_follow_up_exception_after_response(): void
    {
        $followUp = Mockery::mock(RefundCompletionFollowUpService::class);
        $followUp->shouldReceive('run')->once()->andThrow(new RuntimeException('Simulated slow post-commit failure.'));

        $this->app->instance(RefundCompletionFollowUpService::class, $followUp);

        [$ops, $refund] = $this->pendingExecutionRefund();

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-DEFER-3',
                'execution_transaction_id' => 'TXN-DEFER-3',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame('UTR-DEFER-3', $refund->execution_reference_no);
    }

    public function test_closed_refund_repeat_complete_is_idempotent_without_re_execution(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(withHold: true);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-IDEM-1',
                'execution_transaction_id' => 'TXN-IDEM-1',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);
        $executedAt = $refund->executed_at?->toIso8601String();

        Bus::fake([RefundCompletionFollowUpJob::class]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-SHOULD-NOT-APPLY',
                'execution_transaction_id' => 'TXN-SHOULD-NOT-APPLY',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        Bus::assertNotDispatched(RefundCompletionFollowUpJob::class);

        $refund->refresh();
        $this->assertSame('UTR-IDEM-1', $refund->execution_reference_no);
        $this->assertSame($executedAt, $refund->executed_at?->toIso8601String());
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_completed_refund_repost_schedules_follow_up_without_re_execution(): void
    {
        Bus::fake([RefundCompletionFollowUpJob::class]);

        [$ops, $refund, $incident] = $this->pendingExecutionRefund(withHold: true);

        $refund->update([
            'status' => RefundStatus::Completed,
            'executed_by' => $ops->id,
            'executed_at' => now(),
            'execution_reference_no' => 'UTR-STUCK',
            'execution_transaction_id' => 'TXN-STUCK',
        ]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-RETRY',
                'execution_transaction_id' => 'TXN-RETRY',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        Bus::assertDispatched(RefundCompletionFollowUpJob::class);

        $refund->refresh();
        $this->assertSame('UTR-STUCK', $refund->execution_reference_no);
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    /**
     * @return array{0: User, 1: RefundRequest, 2: Incident}
     */
    private function pendingExecutionRefund(bool $withHold = false): array
    {
        $ops = User::factory()->create(['email' => 'ops@example.com']);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-POST-'.uniqid(),
            'serial_number' => 'SN-POST',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => 1000,
            'customer_email' => 'customer@example.com',
            'customer_phone' => '9999999999',
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-POST-'.uniqid(),
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Refund post-commit stability',
            'description' => 'Incident for refund completion post-commit tests.',
            'status' => IncidentStatus::Open,
            'created_by' => $ops->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-'.random_int(100000, 999999),
            'amount' => 1000,
            'refund_amount' => 1000,
            'reason' => 'Post-commit stability coverage.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::BankTransfer,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);

        if ($withHold) {
            app(BusinessHoldService::class)->activateRefundHold($incident, $refund, $ops);
            $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh(), BusinessHoldType::Refund));
        }

        return [$ops, $refund, $incident];
    }
}
