<?php

namespace Tests\Feature;

use Tests\TestCase;

class InteraktFlowWebhookTest extends TestCase
{
    public function test_flow_webhook_returns_not_implemented(): void
    {
        $this->postJson('/api/webhooks/interakt/flow', ['flow_token' => 'example'])
            ->assertStatus(501)
            ->assertJson(['status' => 'not_implemented']);
    }
}
