<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashfreeExistingOrderCentralCustomerBindingTest extends TestCase
{
    use RefreshDatabase;

    private const LINK_EMAIL = 'cashfree-link-bind@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        config(['radiumbox.enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPayload(string $cfPaymentId = '6206001296', string $orderId = 'RD3483569'): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 2,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 1,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Webhook Customer',
                    'customer_email' => self::LINK_EMAIL,
                    'customer_phone' => '9999999999',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    private function seedExistingOrderWithIncident(string $orderBusinessId, ?string $orderEmail): Order
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $existingOrder = Order::query()->create([
            'order_id' => $orderBusinessId,
            'customer_name' => 'Legacy Customer',
            'customer_email' => $orderEmail,
            'customer_phone' => '9900000001',
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        Incident::query()->create([
            'order_id' => $existingOrder->id,
            'reference_no' => 'SC-LINK-'.Str::upper(Str::random(4)),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy import case',
            'description' => 'Pre-existing service case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        return $existingOrder;
    }

    public function test_existing_order_payment_link_sets_customer_id(): void
    {
        $this->seedExistingOrderWithIncident('rd3483569', self::LINK_EMAIL);

        $this->postJson('/api/webhooks/cashfree', $this->linkPayload())->assertOk();

        $order = Order::query()->where('order_id', 'rd3483569')->first();
        $this->assertNotNull($order);
        $this->assertTrue(Str::isUuid((string) $order->customer_id));
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
    }

    public function test_existing_order_link_reuses_exact_email_customer_without_duplicate_wallet(): void
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
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::LINK_EMAIL),
            'verified_at' => now(),
        ]);

        $this->seedExistingOrderWithIncident('rd3483570', self::LINK_EMAIL);

        $this->postJson('/api/webhooks/cashfree', $this->linkPayload('6206001297', 'RD3483570'))->assertOk();

        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());

        $order = Order::query()->where('order_id', 'rd3483570')->first();
        $this->assertSame($existingCustomerId, (string) $order?->customer_id);
    }

    public function test_existing_order_link_replay_does_not_duplicate_customer_or_wallet(): void
    {
        $this->seedExistingOrderWithIncident('rd3483571', self::LINK_EMAIL);

        $payload = $this->linkPayload('6206001298', 'RD3483571');
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $order = Order::query()->where('order_id', 'rd3483571')->first();
        $customerId = (string) $order?->customer_id;

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $order->refresh();
        $this->assertSame($customerId, (string) $order->customer_id);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
        $this->assertSame(3, CashfreeWebhookLog::query()->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)->count());
    }

    public function test_existing_order_link_with_ambiguous_email_does_not_bind(): void
    {
        Schema::table('central_customer_identity_credentials', function ($table): void {
            $table->dropUnique('central_customer_credentials_subject_uq');
        });

        $hash = app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::LINK_EMAIL);
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
                'provider' => CashfreeCentralCustomerBinder::PROVIDER_DESK_EMAIL,
                'subject_hash' => $hash,
                'verified_at' => now(),
            ]);
        }

        $this->seedExistingOrderWithIncident('rd3483572', self::LINK_EMAIL);

        $this->postJson('/api/webhooks/cashfree', $this->linkPayload('6206001299', 'RD3483572'))->assertOk();

        $order = Order::query()->where('order_id', 'rd3483572')->first();
        $this->assertNull($order?->customer_id);
        $this->assertSame(2, CentralCustomer::query()->count());
    }

    public function test_existing_order_link_with_invalid_email_on_order_fails_closed(): void
    {
        $this->seedExistingOrderWithIncident('rd3483573', 'not-an-email');

        $this->postJson('/api/webhooks/cashfree', $this->linkPayload('6206001300', 'RD3483573'))->assertOk();

        $order = Order::query()->where('order_id', 'rd3483573')->first();
        $this->assertNull($order?->customer_id);
        $this->assertSame(0, CentralCustomer::query()->count());
    }

    public function test_existing_order_link_populates_missing_email_from_payload_then_binds(): void
    {
        $this->seedExistingOrderWithIncident('rd3483574', null);

        $this->postJson('/api/webhooks/cashfree', $this->linkPayload('6206001301', 'RD3483574'))->assertOk();

        $order = Order::query()->where('order_id', 'rd3483574')->first();
        $this->assertSame(self::LINK_EMAIL, $order?->customer_email);
        $this->assertTrue(Str::isUuid((string) $order?->customer_id));
        $this->assertSame(1, CentralCustomer::query()->count());
    }

    public function test_existing_order_link_does_not_overwrite_locked_missing_email(): void
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();
        $order = $this->seedExistingOrderWithIncident('rd3483575', null);
        $order->update([
            'customer_email_locked_at' => now(),
            'customer_email_locked_by' => $systemUser->id,
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->linkPayload('6206001302', 'RD3483575'))->assertOk();

        $order->refresh();
        $this->assertNull($order->customer_email);
        $this->assertNull($order->customer_id);
        $this->assertSame(0, CentralCustomer::query()->count());
    }

    public function test_processed_payment_replay_binds_when_customer_id_was_still_null(): void
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();
        $order = Order::query()->create([
            'order_id' => 'RD3483576',
            'customer_email' => self::LINK_EMAIL,
            'customer_name' => 'Replay Customer',
            'status' => OrderStatus::Active,
            'cashfree_payment_id' => '6206001303',
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-REPLAY',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Paid',
            'description' => 'Already paid',
            'status' => IncidentStatus::Closed,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6206001303',
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'incident_id' => $incident->id,
            'request_payload' => $this->linkPayload('6206001303', 'RD3483576'),
            'request_headers' => ['content-type' => 'application/json'],
            'raw_body' => '{}',
            'source_ip' => '127.0.0.1',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $this->assertNull($order->customer_id);

        $log = CashfreeWebhookLog::query()->where('cf_payment_id', '6206001303')->firstOrFail();
        app(CashfreeWebhookProcessorService::class)->process($log->fresh());

        $order->refresh();
        $this->assertTrue(Str::isUuid((string) $order->customer_id));
        $this->assertSame(1, CentralCustomer::query()->count());
    }
}
