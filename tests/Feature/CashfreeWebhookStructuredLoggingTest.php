<?php

namespace Tests\Feature;

use App\Models\CashfreeWebhookLog;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CashfreeWebhookStructuredLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_webhook_log_context_is_structured_and_redacted(): void
    {
        Log::spy();

        $payload = [
            'type' => 'PAYMENT_FAILED_WEBHOOK',
            'data' => [
                'order' => ['order_id' => 'ORDER-LOG-1'],
                'payment' => [
                    'cf_payment_id' => '9988776655',
                    'payment_status' => 'FAILED',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'secret@example.com',
                    'customer_phone' => '9908734801',
                ],
            ],
        ];

        $this->postJson('/api/webhooks/cashfree', $payload, [
            'X-Webhook-Version' => '2023-08-01',
            'x-webhook-signature' => 'super-secret-signature-value',
            'Authorization' => 'Bearer sensitive-token',
        ])->assertOk();

        $encodedPayload = json_encode($payload);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($encodedPayload): bool {
                if ($message !== '[Cashfree Webhook] Received') {
                    return false;
                }

                $required = [
                    'webhook_log_id',
                    'event_type',
                    'cf_payment_id',
                    'cashfree_order_id',
                    'remote_ip',
                    'processing_outcome',
                    'duration_ms',
                ];

                foreach ($required as $key) {
                    if (! array_key_exists($key, $context)) {
                        return false;
                    }
                }

                foreach (['raw_json_body', 'request_headers', 'parsed_payload'] as $forbidden) {
                    if (array_key_exists($forbidden, $context)) {
                        return false;
                    }
                }

                $contextJson = json_encode($context);

                return $context['event_type'] === 'PAYMENT_FAILED_WEBHOOK'
                    && $context['cf_payment_id'] === '9988776655'
                    && $context['cashfree_order_id'] === 'ORDER-LOG-1'
                    && $context['processing_outcome'] === 'ok'
                    && is_string($contextJson)
                    && ! str_contains($contextJson, 'Jane Doe')
                    && ! str_contains($contextJson, 'secret@example.com')
                    && ! str_contains($contextJson, 'super-secret-signature-value')
                    && ! str_contains($contextJson, 'sensitive-token')
                    && ($encodedPayload === false || ! str_contains($contextJson, $encodedPayload));
            })
            ->once();

        $this->assertNotNull(CashfreeWebhookLog::query()->first());
    }
}
