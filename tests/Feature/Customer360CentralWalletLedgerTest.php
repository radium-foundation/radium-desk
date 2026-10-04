<?php

namespace Tests\Feature;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class Customer360CentralWalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => 'cw-c360-ledger-token',
            'central_wallet.ledger_read.customer_history.enabled' => true,
            'central_wallet.ledger_read.customer_history.authorized_source_systems' => [
                'rdservice.in',
                'rdservice.net',
            ],
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);
    }

    public function test_central_wallet_credit_appears_for_rdservice_net_order_ref_67354(): void
    {
        Http::fake();

        $financeUser = $this->financeUser();
        $cwid = $this->fixtureCwidForRef67354();
        [$incident, $refund] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-ref67354@example.com',
            'RN153',
            'REF-67354',
            731,
        );

        $response = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk();

        $html = (string) $response->json('html');
        $this->assertStringContainsString('Current Wallet Balance', $html);
        $this->assertStringContainsString('₹731.00', $html);
        $this->assertStringContainsString('REF-67354', $html);
        $this->assertStringContainsString('CW:72', $html);
        $this->assertStringContainsString('Central Wallet', $html);
        $this->assertStringContainsString(route('refunds.show', $refund->id), $html);

        Http::assertNothingSent();
        $this->assertSame($cwid, $this->resolvedCwidFromFixture('REF-67354'));
    }

    public function test_central_wallet_credit_appears_for_rdservice_net_order_ref_67366(): void
    {
        Http::fake();

        $financeUser = $this->financeUser();
        $this->fixtureCwidForRef67366();
        [$incident, $refund] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-ref67366@example.com',
            'RN158',
            'REF-67366',
            599,
        );

        $response = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk();

        $html = (string) $response->json('html');
        $this->assertStringContainsString('₹599.00', $html);
        $this->assertStringContainsString('REF-67366', $html);
        $this->assertStringContainsString('CW:71', $html);
        $this->assertStringContainsString(route('refunds.show', $refund->id), $html);

        Http::assertNothingSent();
    }

    public function test_central_wallet_debit_appears_correctly(): void
    {
        Http::fake();

        $financeUser = $this->financeUser();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        [$incident] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-debit@example.com',
            'RN200',
            'REF-67399',
            100,
        );

        $this->insertLedgerRow($cwid, 801, 'credit', '100.00', 'desk_refund:RN200', 'REF-67399', '2026-10-03 10:00:00');
        $this->insertLedgerRow($cwid, 802, 'debit', '25.00', 'desk_spend:RN200', 'RN200', '2026-10-03 12:00:00');

        $response = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk();

        $html = (string) $response->json('html');
        $this->assertStringContainsString('₹75.00', $html);
        $this->assertStringContainsString('CW:802', $html);
        $this->assertStringContainsString('DEBIT', $html);
        $this->assertStringContainsString('₹25.00', $html);

        Http::assertNothingSent();
    }

    public function test_legacy_radiumbox_wallet_still_uses_box_api(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/wallet-ledger*' => Http::response([
                'status' => 200,
                'data' => [
                    'balance' => [
                        'available' => 400,
                        'pending_credits' => 0,
                        'pending_debits' => 0,
                    ],
                    'transactions' => [
                        [
                            'id' => 99,
                            'created_at' => now()->toIso8601String(),
                            'type' => 'credit',
                            'credit' => 400,
                            'debit' => null,
                            'status' => 'success',
                            'message' => 'Wallet refund for order RB123',
                            'order_code' => 'RB123',
                            'txnid' => 'RB99',
                            'desk_refund_reference' => 'REF-2026-001234',
                        ],
                    ],
                    'pagination' => ['has_more' => false, 'next_before_id' => null],
                ],
            ], 200),
        ]);

        $financeUser = $this->financeUser();
        [$incident] = $this->centralWalletIncident(
            $financeUser,
            'legacy-box@example.com',
            'RB123',
            'REF-2026-001234',
            400,
        );

        $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk()
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, '₹400.00'));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'radiumbox.test/api/integrations/v1/wallet-ledger');
        });
    }

    public function test_central_wallet_type_filter_limits_rows(): void
    {
        Http::fake();

        $financeUser = $this->financeUser();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        [$incident] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-filter@example.com',
            'RN301',
            'REF-67401',
            100,
        );

        $this->insertLedgerRow($cwid, 901, 'credit', '100.00', 'desk_refund:RN301', 'REF-67401', '2026-10-03 10:00:00');
        $this->insertLedgerRow($cwid, 902, 'debit', '20.00', 'desk_spend:RN301', 'RN301', '2026-10-03 11:00:00');

        $response = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1&type=credit')
            ->assertOk();

        $html = (string) $response->json('html');
        $this->assertStringContainsString('₹100.00', $html);
        $this->assertStringNotContainsString('Central Wallet debit', $html);
        $this->assertStringNotContainsString('CW:802', $html);
    }

    public function test_central_wallet_pagination_exposes_next_before_id(): void
    {
        Http::fake();

        $financeUser = $this->financeUser();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        [$incident] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-page@example.com',
            'RN401',
            'REF-67402',
            10,
        );

        for ($index = 1; $index <= 3; $index++) {
            $this->insertLedgerRow(
                $cwid,
                1000 + $index,
                'credit',
                '10.00',
                'desk_refund:RN401',
                'REF-67402',
                "2026-10-0{$index} 10:00:00",
            );
        }

        config(['central_wallet.ledger_read.default_page_size' => 2]);

        $firstPage = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk();

        $html = (string) $firstPage->json('html');
        $this->assertStringContainsString('Load more', $html);
    }

    public function test_central_wallet_empty_state_when_no_identity_or_entries(): void
    {
        Http::fake();

        $financeUser = $this->financeUser();
        [$incident] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-empty@example.com',
            'RN500',
            'REF-67403',
            0,
        );

        $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk()
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'No Central Wallet account was found'));

        Http::assertNothingSent();
    }

    public function test_central_wallet_and_legacy_paths_do_not_double_count_for_different_order_owners(): void
    {
        Http::fake([
            'radiumbox.test/api/integrations/v1/wallet-ledger*' => Http::response([
                'status' => 200,
                'data' => [
                    'balance' => ['available' => 400, 'pending_credits' => 0, 'pending_debits' => 0],
                    'transactions' => [
                        [
                            'id' => 1,
                            'created_at' => now()->toIso8601String(),
                            'type' => 'credit',
                            'credit' => 400,
                            'status' => 'success',
                            'message' => 'Legacy only',
                            'order_code' => 'RB777',
                            'txnid' => 'RB1',
                            'desk_refund_reference' => 'REF-BOX-777',
                        ],
                    ],
                    'pagination' => ['has_more' => false, 'next_before_id' => null],
                ],
            ], 200),
        ]);

        $financeUser = $this->financeUser();

        [$boxIncident] = $this->centralWalletIncident(
            $financeUser,
            'box-only@example.com',
            'RB777',
            'REF-BOX-777',
            400,
        );

        $boxResponse = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $boxIncident).'?tab=1')
            ->assertOk();

        $boxHtml = (string) $boxResponse->json('html');
        $this->assertStringContainsString('RB1', $boxHtml);
        $this->assertStringNotContainsString('Central Wallet', $boxHtml);

        Http::assertSentCount(1);

        Http::fake();
        $this->fixtureCwidForRef67354();
        [$netIncident] = $this->centralWalletIncident(
            $financeUser,
            'rdnet-only@example.com',
            'RN153',
            'REF-67354',
            731,
        );

        $netResponse = $this->actingAs($financeUser)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $netIncident).'?tab=1')
            ->assertOk();

        $netHtml = (string) $netResponse->json('html');
        $this->assertStringContainsString('Central Wallet', $netHtml);
        $this->assertStringContainsString('CW:72', $netHtml);
        $this->assertStringNotContainsString('RB1', $netHtml);

        Http::assertNothingSent();
    }

    public function test_unauthorized_user_receives_403_for_central_wallet_incident(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->centralWalletIncident($agent, 'rdnet@example.com', 'RN153', 'REF-67354', 731);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertForbidden();
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
    private function centralWalletIncident(
        User $actor,
        string $email,
        string $orderCode,
        string $refundReference,
        int $amount,
    ): array {
        $order = Order::query()->create([
            'order_id' => $orderCode,
            'customer_email' => $email,
            'customer_name' => 'Central Wallet Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Central wallet case',
            'description' => 'Central wallet case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $refundReference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Central wallet refund',
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'status' => RefundStatus::Completed,
            'requested_by' => $actor->id,
        ]);

        return [$incident, $refund];
    }

    private function fixtureCwidForRef67354(): string
    {
        $cwid = '92df23bd-443b-43fe-b657-f86bbffea8df';
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        $this->insertLedgerRow($cwid, 72, 'credit', '731.00', 'desk_refund:RN153', 'REF-67354', '2026-10-04 08:00:00');

        return $cwid;
    }

    private function fixtureCwidForRef67366(): string
    {
        $cwid = '7c9395a7-4b7a-4317-bcff-d15d87007b54';
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        $this->insertLedgerRow($cwid, 71, 'credit', '599.00', 'desk_refund:RN158', 'REF-67366', '2026-10-04 09:00:00');

        return $cwid;
    }

    private function insertLedgerRow(
        string $cwid,
        int $ledgerEntryId,
        string $entryType,
        string $amount,
        string $sourceReference,
        string $businessReference,
        string $postedAt,
    ): void {
        DB::table('central_wallet_ledger_entries')->insert([
            'id' => $ledgerEntryId,
            'central_wallet_id' => $cwid,
            'entry_type' => $entryType,
            'amount' => $amount,
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted->value,
            'source_system' => 'rdservice.net',
            'source_reference' => $sourceReference,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => $businessReference,
            'posted_at' => CarbonImmutable::parse($postedAt),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolvedCwidFromFixture(string $refundReference): string
    {
        return (string) CentralWalletLedgerEntry::query()
            ->where('business_reference', $refundReference)
            ->value('central_wallet_id');
    }
}
