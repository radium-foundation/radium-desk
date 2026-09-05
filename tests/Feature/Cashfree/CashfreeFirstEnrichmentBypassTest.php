<?php

namespace Tests\Feature\Cashfree;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeRadiumBoxBypassMetrics;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeFirstEnrichmentBypassTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.verify_signature' => false,
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.admin_fallback_enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->ensureCashfreeSystemUser();
        $this->seed(SettingsSeeder::class);

        Queue::fake();
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(
        string $cfPaymentId,
        string $orderId,
        ?array $orderTags,
    ): array {
        $payload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2026-08-07T23:09:12+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 499,
                    'order_currency' => 'INR',
                    'order_tags' => $orderTags,
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 499,
                    'payment_currency' => 'INR',
                    'payment_time' => '2026-08-07T23:09:00+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Bypass Customer',
                    'customer_email' => 'bypass@example.com',
                    'customer_phone' => '9908734801',
                ],
            ],
        ];

        if ($orderTags === null) {
            $payload['data']['order']['order_tags'] = null;
        }

        return $payload;
    }

    public function test_complete_order_tags_mark_synced_and_skip_radiumbox_job(): void
    {
        Http::fake();

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload(
            cfPaymentId: '6190000001',
            orderId: 'RD3480001',
            orderTags: [
                'product_name' => 'MSO 1300 E3 RD L1',
                'rd_service_name' => '1 Year Unlimited',
                'serial_no' => '2521i006956',
            ],
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6190000001')->first();
        $this->assertNotNull($order);
        $this->assertSame('2521I006956', $order->serial_number);
        $this->assertSame('MSO 1300 E3 RD L1', $order->product_name);
        $this->assertSame(['1 Year Unlimited'], $order->service_history);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Synced,
            $order->fresh()->radiumbox_sync_status,
        );

        Queue::assertNotPushed(RadiumBoxOrderEnrichmentJob::class);
        Http::assertNothingSent();

        $audit = AuditLog::query()
            ->where('event', RadiumBoxSyncAuditService::EVENT_ENRICHMENT_COMPLETED)
            ->where('auditable_id', $order->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            RadiumBoxOrderEnrichmentService::SYNC_SOURCE_CASHFREE_ORDER_TAGS,
            $audit->new_values['sync_source'] ?? null,
        );
        $this->assertTrue($audit->new_values['radiumbox_job_bypassed'] ?? false);

        $metrics = app(CashfreeRadiumBoxBypassMetrics::class)->snapshot();
        $this->assertSame(1, $metrics['decisions']);
        $this->assertSame(1, $metrics['bypassed']);
        $this->assertSame(0, $metrics['fallback_dispatched']);
        $this->assertSame(100.0, $metrics['bypass_percentage']);
    }

    public function test_incomplete_order_tags_dispatch_radiumbox_job(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload(
            cfPaymentId: '6190000002',
            orderId: 'RD3480002',
            orderTags: [
                'product_name' => 'MFS110',
                'rd_service_name' => '1 Year Unlimited',
                'serial_no' => '',
            ],
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6190000002')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->serial_number);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            $order->fresh()->radiumbox_sync_status,
        );

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });

        $metrics = app(CashfreeRadiumBoxBypassMetrics::class)->snapshot();
        $this->assertSame(1, $metrics['decisions']);
        $this->assertSame(0, $metrics['bypassed']);
        $this->assertSame(1, $metrics['fallback_dispatched']);
        $this->assertSame(0.0, $metrics['bypass_percentage']);
    }

    public function test_null_order_tags_dispatch_radiumbox_job(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload(
            cfPaymentId: '6190000003',
            orderId: 'RD3480003',
            orderTags: null,
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6190000003')->first();
        $this->assertNotNull($order);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Pending,
            $order->fresh()->radiumbox_sync_status,
        );

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_manual_sync_path_remains_available_after_cashfree_bypass(): void
    {
        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload(
            cfPaymentId: '6190000004',
            orderId: 'RD3480004',
            orderTags: [
                'product_name' => 'MSO 1300 E3 RD L1',
                'rd_service_name' => '1 Year Unlimited',
                'serial_no' => '2521i006957',
            ],
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6190000004')->first();
        $this->assertNotNull($order);
        Queue::assertNotPushed(RadiumBoxOrderEnrichmentJob::class);

        $actor = User::factory()->create();
        $result = app(RadiumBoxOrderEnrichmentService::class)->manualSync($order->fresh(), $actor);

        $this->assertTrue($result->success);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::Synced,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_cashfree_fallback_job_still_enriches_missing_serial(): void
    {
        Http::fake([
            'https://admin.radiumbox.com/*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3480005',
                        'serial_no' => '7891312',
                        'product_name' => 'MFS110',
                        'rd_service_name' => '1 Year Unlimited',
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload(
            cfPaymentId: '6190000005',
            orderId: 'RD3480005',
            orderTags: [
                'product_name' => 'MFS110',
                'rd_service_name' => '1 Year Unlimited',
                'serial_no' => '',
            ],
        ))->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '6190000005')->first();
        $this->assertNotNull($order);
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class);

        app(RadiumBoxOrderEnrichmentService::class)->process($order->id, attempt: 1);

        $order->refresh();
        $this->assertSame('7891312', $order->serial_number);
        $this->assertSame('MFS110', $order->product_name);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $order->radiumbox_sync_status);
        Http::assertSentCount(1);
    }
}
