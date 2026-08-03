<?php

namespace Tests\Unit\IncomingEmail\Gmail;

use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class GmailAccessTokenServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'inbound_email.gmail.token_url' => 'https://oauth2.googleapis.com/token',
            'inbound_email.gmail.timeout_seconds' => 5,
            'inbound_email.gmail.connect_timeout_seconds' => 2,
            'inbound_email.gmail.scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
            ],
            'inbound_email.gmail.service_account_json' => json_encode([
                'client_email' => 'sa@test.iam.gserviceaccount.com',
                'private_key' => $this->ephemeralPrivateKeyPem(),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_token_endpoint_failure_includes_google_error_body_without_jwt(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid email or User ID',
            ], 401),
        ]);

        Log::spy();

        try {
            app(GmailAccessTokenService::class)->tokenForMailbox('support@radiumbox.com');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('HTTP 401', $message);
            $this->assertStringContainsString('"error":"invalid_grant"', $message);
            $this->assertStringContainsString('"error_description":"Invalid email or User ID"', $message);
            $this->assertStringNotContainsString('eyJ', $message);
            $this->assertStringNotContainsString('assertion', strtolower($message));
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $message);
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[GmailInbound] OAuth token endpoint rejected request.'
                    && ($context['http_status'] ?? null) === 401
                    && ($context['error'] ?? null) === 'invalid_grant'
                    && ($context['error_description'] ?? null) === 'Invalid email or User ID'
                    && is_string($context['response_body'] ?? null)
                    && str_contains((string) $context['response_body'], 'invalid_grant')
                    && ! str_contains((string) $context['response_body'], 'eyJ');
            });
    }

    public function test_token_endpoint_failure_redacts_assertion_and_private_key_fields(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'unauthorized_client',
                'assertion' => 'eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJzYSJ9.signature',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----",
            ], 403),
        ]);

        try {
            app(GmailAccessTokenService::class)->tokenForMailbox('support@radiumbox.com');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('HTTP 403', $message);
            $this->assertStringContainsString('"error":"unauthorized_client"', $message);
            $this->assertStringContainsString('[redacted]', $message);
            $this->assertStringNotContainsString('eyJhbGciOiJSUzI1NiJ9', $message);
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $message);
        }
    }

    public function test_token_endpoint_failure_handles_plain_text_body(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response('access_denied', 400),
        ]);

        try {
            app(GmailAccessTokenService::class)->tokenForMailbox('support@radiumbox.com');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
            $this->assertStringContainsString('access_denied', $exception->getMessage());
        }
    }

    public function test_successful_token_response_unchanged(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $token = app(GmailAccessTokenService::class)->tokenForMailbox('support@radiumbox.com');

        $this->assertSame('ya29.test-token', $token);
    }

    private function ephemeralPrivateKeyPem(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key, 'Failed to generate ephemeral RSA key for test.');

        $pem = '';
        $exported = openssl_pkey_export($key, $pem);
        $this->assertTrue($exported, 'Failed to export ephemeral RSA key for test.');
        $this->assertNotSame('', $pem);

        return $pem;
    }
}
