<?php

namespace Tests\Feature\Cashfree;

use App\Models\CashfreeWebhookLog;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookStageTiming;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CashfreeWebhookStageTimingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ALLOWED_LOG_KEYS = [
        'event',
        'request_correlation_id',
        'webhook_log_id',
        'stage',
        'duration_ms',
        'outcome',
        'retry_attempt',
        'retry_count',
        'listener_enqueue_count',
        'http_status',
        'error_category',
    ];

    /** @var list<string> */
    private const EXPECTED_SUCCESS_STAGES = [
        'request_entry',
        'store_webhook',
        'signature_verification',
        'preflight',
        'payment_lookup',
        'persist_attempt',
        'transaction_persistence',
        'transaction_commit',
        'mark_processed',
        'outbox_writes',
        'order_paid_dispatch',
        'redis_enqueue',
        'fresh_incident',
        'forensic_logging',
        'response_handoff',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false, 'radiumbox.enabled' => false]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 2,
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 1,
                    'payment_group' => 'upi',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'secret.pii@example.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    public function test_successful_webhook_emits_ordered_redacted_stage_timings(): void
    {
        $this->captureStageTimingLogs();

        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk();

        $stageLogs = $this->capturedStageLogs;
        $this->assertNotEmpty($stageLogs);

        $correlationIds = collect($stageLogs)->pluck('request_correlation_id')->unique();
        $this->assertCount(1, $correlationIds);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $correlationIds->first(),
        );

        $webhookLog = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($webhookLog);
        $storeStage = collect($stageLogs)->firstWhere('stage', 'store_webhook');
        $this->assertIsArray($storeStage);
        $this->assertSame($webhookLog->id, $storeStage['webhook_log_id'] ?? null);

        foreach ($stageLogs as $context) {
            $this->assertSame(CashfreeWebhookStageTiming::LOG_EVENT, $context['event'] ?? null);
            $this->assertAllowlistedKeysOnly($context);
            $this->assertNoSensitiveLeakage($context);
        }

        $stages = collect($stageLogs)->pluck('stage')->all();
        foreach (self::EXPECTED_SUCCESS_STAGES as $expected) {
            $this->assertContains($expected, $stages, "Missing stage: {$expected}");
        }

        $handoff = collect($stageLogs)->firstWhere('stage', 'response_handoff');
        $this->assertIsArray($handoff);
        $this->assertSame('ok', $handoff['outcome']);
        $this->assertSame(200, $handoff['http_status']);
    }

    public function test_signature_failure_emits_signature_and_response_handoff_stages(): void
    {
        config(['cashfree.verify_signature' => true]);

        $this->captureStageTimingLogs();

        $payload = $this->successfulPayload();
        $response = $this->postJson('/api/webhooks/cashfree', $payload, [
            'x-webhook-signature' => 'invalid',
            'x-webhook-timestamp' => (string) now()->timestamp,
        ]);

        $response->assertStatus(401);

        $stages = collect($this->capturedStageLogs)->pluck('stage')->all();
        $this->assertContains('request_entry', $stages);
        $this->assertContains('store_webhook', $stages);
        $this->assertContains('signature_verification', $stages);
        $this->assertContains('forensic_logging', $stages);
        $this->assertContains('response_handoff', $stages);
        $this->assertNotContains('preflight', $stages);
    }

    public function test_persistence_failure_emits_stages_before_failure_without_payment_leakage(): void
    {
        $this->captureStageTimingLogs();

        $response = $this->postJson('/api/webhooks/cashfree', [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'payment' => [
                    'cf_payment_id' => '1453002795',
                    'payment_status' => 'SUCCESS',
                ],
            ],
        ]);

        $response->assertOk();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);

        $contexts = $this->capturedStageLogs;
        $this->assertNotEmpty($contexts);
        foreach ($contexts as $context) {
            $this->assertAllowlistedKeysOnly($context);
            $this->assertNoSensitiveLeakage($context);
        }

        $stages = collect($contexts)->pluck('stage')->all();
        $this->assertContains('request_entry', $stages);
        $this->assertContains('store_webhook', $stages);
        $this->assertContains('preflight', $stages);
        $this->assertContains('response_handoff', $stages);
    }

    public function test_interakt_webhook_does_not_emit_cashfree_stage_timings(): void
    {
        $stageLogs = $this->captureStageTimingLogs();

        $this->postJson('/api/webhooks/interakt', ['version' => '1.0', 'type' => 'ignore']);

        $this->assertSame([], $stageLogs);
    }

    /** @var list<array<string, mixed>> */
    private array $capturedStageLogs = [];

    /**
     * @return list<array<string, mixed>>
     */
    private function captureStageTimingLogs(): array
    {
        $this->capturedStageLogs = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if ($event->level !== 'info' || $event->message !== CashfreeWebhookStageTiming::LOG_EVENT) {
                return;
            }

            if (! is_array($event->context)) {
                return;
            }

            $this->capturedStageLogs[] = $event->context;
        });

        return $this->capturedStageLogs;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertAllowlistedKeysOnly(array $context): void
    {
        foreach (array_keys($context) as $key) {
            $this->assertContains($key, self::ALLOWED_LOG_KEYS, "Unexpected log key: {$key}");
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertNoSensitiveLeakage(array $context): void
    {
        $encoded = json_encode($context);
        $this->assertIsString($encoded);

        $this->assertStringNotContainsString('secret.pii@example.com', $encoded);
        $this->assertStringNotContainsString('9908734801', $encoded);
        $this->assertStringNotContainsString('Jane Doe', $encoded);
        $this->assertStringNotContainsString('1453002795', $encoded);
        $this->assertStringNotContainsString('order_OFR_2', $encoded);
        $this->assertStringNotContainsString('x-webhook-signature', $encoded);
        $this->assertStringNotContainsString('SELECT', $encoded);
    }
}
