<?php

namespace Tests\Feature\Cashfree;

use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Services\Cashfree\CashfreeHealthService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeSystemUserPreflightTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        config(['radiumbox.enabled' => false]);
        Queue::fake();
    }

    public function test_missing_system_user_fails_webhook_with_explicit_error_and_high_severity_audit(): void
    {
        config([
            'cashfree.verify_signature' => false,
            'cashfree.system_user_email' => 'absent-cashfree@radium.local',
        ]);

        $payload = $this->successfulPayload('preflight-missing-1', 'order-preflight-missing');

        $log = CashfreeWebhookLog::query()->create([
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $result = app(CashfreeWebhookProcessorService::class)->process($log)->fresh();

        $this->assertSame(CashfreeWebhookProcessorService::STATUS_FAILED, $result->processing_status);
        $this->assertStringContainsString('absent-cashfree@radium.local', (string) $result->processing_error);
        $this->assertSame(0, Order::query()->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => CashfreeWebhookProcessorService::AUDIT_EVENT_SYSTEM_USER_MISSING,
            'auditable_type' => $log->getMorphClass(),
            'auditable_id' => $log->id,
        ]);

        $audit = AuditLog::query()
            ->where('event', CashfreeWebhookProcessorService::AUDIT_EVENT_SYSTEM_USER_MISSING)
            ->firstOrFail();

        $this->assertSame('high', $audit->new_values['severity'] ?? null);
        $this->assertSame('absent-cashfree@radium.local', $audit->new_values['configured_email'] ?? null);
    }

    public function test_healthy_system_user_still_creates_order(): void
    {
        $this->ensureCashfreeSystemUser();

        $payload = $this->successfulPayload('preflight-ok-1', 'order-preflight-ok');

        $log = CashfreeWebhookLog::query()->create([
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $result = app(CashfreeWebhookProcessorService::class)->process($log)->fresh();

        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $result->processing_status);
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', 'preflight-ok-1')->count());
        $this->assertSame(
            CashfreeHealthService::SYSTEM_USER_STATUS_HEALTHY,
            app(CashfreeHealthService::class)->systemUserCheck()['status'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId, string $orderId): array
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
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
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
}
