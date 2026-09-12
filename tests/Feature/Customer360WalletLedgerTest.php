<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Customer360WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);
    }

    public function test_authorized_finance_user_can_view_wallet_ledger_tab(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/wallet-ledger*' => Http::response([
                'status' => 200,
                'data' => [
                    'customer' => ['userid' => 10, 'email' => 'wallet@example.com', 'name' => 'Wallet Customer'],
                    'balance' => [
                        'available' => 400,
                        'pending_credits' => 50,
                        'pending_debits' => 0,
                        'cached_wallet_amount' => 400,
                    ],
                    'last_activity_at' => now()->toIso8601String(),
                    'transactions' => [
                        [
                            'id' => 99,
                            'created_at' => now()->toIso8601String(),
                            'type' => 'credit',
                            'credit' => 400,
                            'debit' => null,
                            'status' => 'success',
                            'message' => 'Wallet refund for order RD123 (Desk REF-2026-001234)',
                            'orderid' => 55,
                            'order_code' => 'RD123',
                            'txnid' => 'RD99',
                            'desk_refund_reference' => 'REF-2026-001234',
                            'admin_id' => null,
                            'missing_order_link' => false,
                        ],
                    ],
                    'pagination' => ['limit' => 25, 'has_more' => false, 'next_before_id' => null],
                ],
            ], 200),
        ]);

        $financeUser = $this->financeUser();
        [$incident, $refund] = $this->walletIncident($financeUser, 'wallet@example.com', 'RD123');

        $response = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk();

        $html = (string) $response->json('html');
        $this->assertStringContainsString('Current Wallet Balance', $html);
        $this->assertStringContainsString('₹400.00', $html);
        $this->assertStringContainsString('REF-2026-001234', $html);
        $this->assertStringContainsString(route('refunds.show', $refund->id), $html);
    }

    public function test_unauthorized_user_receives_403(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->walletIncident($agent, 'wallet@example.com', 'RD999');

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertForbidden();
    }

    public function test_customer_isolation_uses_incident_customer_email_not_query_override(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/wallet-ledger*' => Http::response([
                'status' => 200,
                'data' => [
                    'balance' => ['available' => 0, 'pending_credits' => 0, 'pending_debits' => 0],
                    'transactions' => [],
                    'pagination' => ['has_more' => false, 'next_before_id' => null],
                ],
            ], 200),
        ]);

        $financeUser = $this->financeUser();
        [$incident] = $this->walletIncident($financeUser, 'customer-a@example.com', 'RD111');

        $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1&customer_email=customer-b@example.com')
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'customer_email=customer-a%40example.com')
                && ! str_contains($request->url(), 'customer-b%40example.com');
        });
    }

    public function test_wallet_tab_is_not_rendered_without_permission(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->walletIncident($agent, 'wallet@example.com', 'RD222');

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertDontSee('data-customer-360-tab="wallet-ledger"', false);
    }

    public function test_box_api_failure_returns_validation_error_payload(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/wallet-ledger*' => Http::response([
                'status' => 500,
                'message' => 'Internal server error',
            ], 500),
        ]);

        $financeUser = $this->financeUser();
        [$incident] = $this->walletIncident($financeUser, 'wallet@example.com', 'RD333');

        $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Internal server error');
    }

    private function financeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }

    /**
     * @return array{0: Incident, 1: RefundRequest}
     */
    private function walletIncident(User $actor, string $email, string $orderCode): array
    {
        $order = Order::query()->create([
            'order_id' => $orderCode,
            'customer_email' => $email,
            'customer_name' => 'Wallet Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Wallet case',
            'description' => 'Wallet case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-001234',
            'amount' => 400,
            'refund_amount' => 400,
            'reason' => 'Wallet refund',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Completed,
            'requested_by' => $actor->id,
        ]);

        return [$incident, $refund];
    }
}
