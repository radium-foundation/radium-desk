<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Wallet\DeskCustomerCentralWalletResolver;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashfreeCentralCustomerBindingTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'cashfree-bind-new@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        config(['radiumbox.enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(
        string $cfPaymentId = '1453002795',
        string $orderId = 'RD90001',
        string $email = self::EMAIL,
    ): array {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 499,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 499,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => $email,
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    public function test_new_cashfree_email_creates_one_customer_wallet_and_sets_order_customer_id(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload())
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD90001')->first();
        $this->assertNotNull($order);
        $this->assertTrue(Str::isUuid((string) $order->customer_id));

        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());

        $customer = CentralCustomer::query()->find($order->customer_id);
        $this->assertNotNull($customer);

        $this->assertSame(
            '0.00',
            app(LedgerService::class)->availableBalance((string) $customer->central_wallet_id),
        );

        $hash = app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::EMAIL);
        $credential = CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customer->id)
            ->where('provider', CashfreeCentralCustomerBinder::PROVIDER_DESK_EMAIL)
            ->where('subject_hash', $hash)
            ->first();
        $this->assertNotNull($credential);
        $this->assertSame('cashfree_webhook', $credential->metadata['source'] ?? null);
    }

    public function test_existing_exact_email_customer_is_reused_without_duplicate_wallet(): void
    {
        $existingCustomerId = (string) Str::uuid();
        $existingWalletId = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $existingWalletId, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $existingCustomerId,
            'central_wallet_id' => $existingWalletId,
            'status' => 'active',
        ]);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $existingCustomerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::EMAIL),
            'verified_at' => now(),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002796', 'RD90002'))
            ->assertOk();

        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());

        $order = Order::query()->where('order_id', 'RD90002')->first();
        $this->assertSame($existingCustomerId, (string) $order?->customer_id);
    }

    public function test_two_cashfree_orders_with_same_email_share_customer_and_wallet(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002797', 'RD90003'))
            ->assertOk();
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002798', 'RD90004'))
            ->assertOk();

        $first = Order::query()->where('order_id', 'RD90003')->first();
        $second = Order::query()->where('order_id', 'RD90004')->first();

        $this->assertSame((string) $first?->customer_id, (string) $second?->customer_id);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
    }

    public function test_cashfree_webhook_retry_does_not_duplicate_customer_or_change_binding(): void
    {
        $payload = $this->successfulPayload('1453002799', 'RD90005');
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $order = Order::query()->where('order_id', 'RD90005')->first();
        $customerId = (string) $order?->customer_id;

        $log = CashfreeWebhookLog::query()->where('cf_payment_id', '1453002799')->first();
        $this->assertNotNull($log);
        app(CashfreeWebhookProcessorService::class)->process($log->fresh());

        $order->refresh();
        $this->assertSame($customerId, (string) $order->customer_id);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
        $this->assertSame(1, CentralCustomerIdentityCredential::query()->count());
    }

    public function test_ambiguous_email_identity_does_not_bind_order(): void
    {
        \Illuminate\Support\Facades\Schema::table('central_customer_identity_credentials', function ($table): void {
            $table->dropUnique('central_customer_credentials_subject_uq');
        });

        $hash = app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::EMAIL);
        foreach (['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'] as $customerId) {
            $walletId = (string) Str::uuid();
            CentralWallet::query()->create(['id' => $walletId, 'status' => 'active']);
            CentralCustomer::query()->create([
                'id' => $customerId,
                'central_wallet_id' => $walletId,
                'status' => 'active',
            ]);
            CentralCustomerIdentityCredential::query()->create([
                'desk_customer_id' => $customerId,
                'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
                'provider' => 'desk_email',
                'subject_hash' => $hash,
                'verified_at' => now(),
            ]);
        }

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002800', 'RD90006'))
            ->assertOk();

        $order = Order::query()->where('order_id', 'RD90006')->first();
        $this->assertNull($order?->customer_id);
        $this->assertSame(2, CentralCustomer::query()->count());
    }

    public function test_invalid_email_leaves_order_unbound_without_creating_customer(): void
    {
        $payload = $this->successfulPayload('1453002801', 'RD90007', 'not-an-email');
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $order = Order::query()->where('order_id', 'RD90007')->first();
        $this->assertNull($order?->customer_id);
        $this->assertSame(0, CentralCustomer::query()->count());
    }

    public function test_c360_displays_wallet_from_order_customer_id_without_desk_email_credential(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $customerId = (string) Str::uuid();
        $walletId = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $walletId, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $walletId,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD90008',
            'customer_email' => 'unverified-display@example.com',
            'customer_name' => 'Display Test',
            'customer_id' => $customerId,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC90008',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Wallet display',
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $resolved = app(DeskCustomerCentralWalletResolver::class)->forIncident($incident->fresh());
        $this->assertSame('resolved', $resolved['state']);
        $this->assertSame($customerId, $resolved['customer_id']);
        $this->assertSame($walletId, $resolved['central_wallet_id']);
    }

    public function test_cashfree_bind_reuses_desk_email_customer_instead_of_creating_second_wallet(): void
    {
        $existingCustomerId = (string) Str::uuid();
        $existingWalletId = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $existingWalletId, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $existingCustomerId,
            'central_wallet_id' => $existingWalletId,
            'status' => 'active',
        ]);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $existingCustomerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::EMAIL),
            'verified_at' => now(),
            'metadata' => ['source' => 'linked_account_credential_ensure'],
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002803', 'RD90010'))
            ->assertOk();

        $this->assertSame(1, CentralCustomer::query()->count());
        $order = Order::query()->where('order_id', 'RD90010')->first();
        $this->assertSame($existingCustomerId, (string) $order?->customer_id);
    }

    public function test_end_to_end_cashfree_to_c360_wallet_display(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload('1453002802', 'RD90009', 'e2e-wallet@example.com'))
            ->assertOk();

        $incident = Incident::query()->whereHas('order', fn ($q) => $q->where('order_id', 'RD90009'))->first();
        $this->assertNotNull($incident);

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertOk();

        $this->assertStringContainsString('Central Wallet', (string) $response->json('html'));
        $this->assertStringContainsString('One customer wallet', (string) $response->json('html'));
    }
}
