<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Http\Controllers\RefundRequestController;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class RefundCompletionBrowserGetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
    }

    public function test_completion_route_stays_post_only_and_browser_get_is_a_separate_redirect(): void
    {
        $complete = app('router')->getRoutes()->getByName('refunds.complete');
        $browser = app('router')->getRoutes()->getByName('refunds.complete.browser');

        $this->assertNotNull($complete);
        $this->assertNotNull($browser);
        $this->assertSame(['POST'], $complete->methods());
        $this->assertContains('GET', $browser->methods());
        $this->assertNotContains('POST', $browser->methods());
        $this->assertSame(RefundRequestController::class.'@complete', $complete->getActionName());
        $this->assertSame(RefundRequestController::class.'@redirectBrowserCompletion', $browser->getActionName());
        $this->assertContains('web', $complete->middleware());
        $this->assertContains('auth', $complete->middleware());
        $this->assertContains('active', $complete->middleware());
    }

    public function test_authorized_get_returns_to_the_refund_page_without_completing(): void
    {
        $ops = $this->operationsAdmin();
        $refund = $this->pendingWalletRefund($ops);
        $incidentId = $refund->incident_id;

        $this->mock(RefundRequestService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('complete');
        });

        $this->actingAs($ops)
            ->get(route('refunds.complete', $refund))
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas(
                'refund_completion_notice',
                'Refund completion must be submitted from this page. Opening the completion address does not complete the refund.',
            );

        $this->actingAs($ops)
            ->followingRedirects()
            ->get(route('refunds.complete', $refund))
            ->assertOk()
            ->assertSee('Opening the completion address does not complete the refund.', false)
            ->assertSee('action="'.route('refunds.complete', $refund).'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('Mark Refund Completed', false);

        $refund->refresh();
        $this->assertSame(RefundStatus::PendingExecution, $refund->status);
        $this->assertNull($refund->executed_at);
        $this->assertNull($refund->execution_reference_no);
        $this->assertNull($refund->refund_transaction_id);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'refund.completed',
            'auditable_id' => $refund->id,
        ]);
        $this->assertDatabaseCount('central_wallet_ledger_entries', 0);
        $this->assertSame('open', Incident::query()->findOrFail($incidentId)->status->value);
    }

    public function test_repeated_get_does_not_change_a_rejected_or_pending_refund(): void
    {
        $ops = $this->operationsAdmin();
        $pending = $this->pendingWalletRefund($ops, 'REF-2026-000461');
        $rejected = $this->pendingWalletRefund($ops, 'REF-2026-000462', RefundStatus::Rejected);

        $this->mock(RefundRequestService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('complete');
        });

        $this->actingAs($ops)->get(route('refunds.complete', $pending))->assertRedirect();
        $this->actingAs($ops)->get(route('refunds.complete', $pending))->assertRedirect();
        $this->actingAs($ops)->get(route('refunds.complete', $rejected))->assertRedirect();

        $this->assertSame(RefundStatus::PendingExecution, $pending->refresh()->status);
        $this->assertSame(RefundStatus::Rejected, $rejected->refresh()->status);
        $this->assertDatabaseCount('central_wallet_ledger_entries', 0);
    }

    public function test_guest_and_unauthorized_get_do_not_complete_the_refund(): void
    {
        $ops = $this->operationsAdmin();
        $refund = $this->pendingWalletRefund($ops);
        $stranger = User::factory()->create();

        $this->get(route('refunds.complete', $refund))
            ->assertRedirect(route('login'));

        $this->actingAs($stranger)
            ->get(route('refunds.complete', $refund))
            ->assertForbidden();

        $this->assertSame(RefundStatus::PendingExecution, $refund->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'refund.completed',
            'auditable_id' => $refund->id,
        ]);
        $this->assertDatabaseCount('central_wallet_ledger_entries', 0);
    }

    public function test_agent_post_remains_forbidden_and_csrf_still_blocks_completion(): void
    {
        $ops = $this->operationsAdmin();
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $refund = $this->pendingWalletRefund($ops);

        $this->actingAs($agent)
            ->post(route('refunds.complete', $refund), [])
            ->assertForbidden();

        $this->assertSame(RefundStatus::PendingExecution, $refund->refresh()->status);

        $this->app['env'] = 'production';

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [])
            ->assertStatus(419);

        $this->assertSame(RefundStatus::PendingExecution, $refund->refresh()->status);
        $this->assertNull($refund->executed_at);
        $this->assertDatabaseCount('central_wallet_ledger_entries', 0);
    }

    private function operationsAdmin(): User
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $ops;
    }

    private function pendingWalletRefund(
        User $ops,
        string $reference = 'REF-2026-000460',
        RefundStatus $status = RefundStatus::PendingExecution,
    ): RefundRequest {
        $order = Order::query()->create([
            'order_id' => 'RD-'.$reference,
            'serial_number' => 'SN-'.$reference,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => 617,
            'created_by' => $ops->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.$reference,
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Refund completion method test',
            'description' => 'Local fixture only.',
            'status' => 'open',
            'created_by' => $ops->id,
        ]);

        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => 617,
            'refund_amount' => 617,
            'reason' => 'Browser GET must not complete this refund.',
            'status' => $status,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => [],
        ]);
    }
}
