<?php

namespace Tests\Feature;

use Tests\TestCase;

class CashfreeWebhookTest extends TestCase
{
    public function test_webhook_is_publicly_accessible_and_returns_200(): void
    {
        $response = $this->postJson('/api/webhooks/cashfree', [
            'type' => 'PAYMENT_SUCCESS',
            'data' => ['order' => ['order_id' => 'ORD-123']],
        ], [
            'X-Cashfree-Signature' => 'test-signature',
            'User-Agent' => 'Cashfree-Webhook/1.0',
        ]);

        $response->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_webhook_rejects_non_post_requests(): void
    {
        $this->getJson('/api/webhooks/cashfree')->assertMethodNotAllowed();
    }
}
