<?php

namespace Tests\Unit;

use App\Services\Cashfree\CashfreeWebhookSignatureVerifier;
use Illuminate\Http\Request;
use Tests\TestCase;

class CashfreeWebhookSignatureVerifierTest extends TestCase
{
    private CashfreeWebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.client_secret' => 'test-client-secret']);
        $this->verifier = new CashfreeWebhookSignatureVerifier;
    }

    public function test_valid_signature_is_accepted(): void
    {
        $rawBody = '{"type":"PAYMENT_SUCCESS_WEBHOOK"}';
        $timestamp = '1617695238078';
        $signature = base64_encode(hash_hmac('sha256', $timestamp.$rawBody, 'test-client-secret', true));

        $request = Request::create(
            '/api/webhooks/cashfree',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawBody,
        );

        $this->assertTrue($this->verifier->hasRequiredHeaders($request));
        $this->assertTrue($this->verifier->verify($request));
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $rawBody = '{"type":"PAYMENT_SUCCESS_WEBHOOK"}';
        $timestamp = '1617695238078';

        $request = Request::create(
            '/api/webhooks/cashfree',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid-signature',
            ],
            $rawBody,
        );

        $this->assertTrue($this->verifier->hasRequiredHeaders($request));
        $this->assertFalse($this->verifier->verify($request));
    }

    public function test_missing_headers_are_detected(): void
    {
        $request = Request::create(
            '/api/webhooks/cashfree',
            'POST',
            content: '{"type":"PAYMENT_SUCCESS_WEBHOOK"}',
        );

        $this->assertFalse($this->verifier->hasRequiredHeaders($request));
        $this->assertFalse($this->verifier->verify($request));
    }
}
