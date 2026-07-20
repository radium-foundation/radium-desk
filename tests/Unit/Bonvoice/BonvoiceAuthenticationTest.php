<?php

namespace Tests\Unit\Bonvoice;

use App\Services\Bonvoice\BonvoiceAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BonvoiceAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.click_to_call.base_url' => 'https://backend.pbx.bonvoice.com',
            'bonvoice.click_to_call.username' => 'api-user',
            'bonvoice.click_to_call.password' => 'api-pass',
        ]);

        Cache::flush();
    }

    public function test_headers_use_token_authorization_scheme(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => [
                    'token' => 'bonvoice-test-token',
                ],
            ], 200),
        ]);

        $headers = app(BonvoiceAuthentication::class)->headers();

        $this->assertSame('Token bonvoice-test-token', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Accept']);
    }

    public function test_token_is_cached_after_first_authentication(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => [
                    'token' => 'cached-token',
                ],
            ], 200),
        ]);

        $auth = app(BonvoiceAuthentication::class);

        $this->assertSame('cached-token', $auth->token());
        $this->assertSame('cached-token', $auth->token());

        Http::assertSentCount(1);
    }

    public function test_forget_token_clears_cached_token(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::sequence()
                ->push([
                    'status' => '1',
                    'data' => ['token' => 'first-token'],
                ], 200)
                ->push([
                    'status' => '1',
                    'data' => ['token' => 'second-token'],
                ], 200),
        ]);

        $auth = app(BonvoiceAuthentication::class);

        $this->assertSame('first-token', $auth->token());
        $auth->forgetToken();
        $this->assertSame('second-token', $auth->token());

        Http::assertSentCount(2);
    }

    public function test_token_uses_expires_in_from_auth_response(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => [
                    'token' => 'expiring-token',
                    'expires_in' => 3600,
                ],
            ], 200),
        ]);

        $auth = app(BonvoiceAuthentication::class);

        $this->assertSame('expiring-token', $auth->token());
        $this->assertTrue(Cache::has('bonvoice.api.auth_token'));
        $this->assertSame('expiring-token', Cache::get('bonvoice.api.auth_token'));
    }

    public function test_token_is_cached_without_ttl_when_expiry_missing(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => [
                    'token' => 'long-lived-token',
                ],
            ], 200),
        ]);

        $auth = app(BonvoiceAuthentication::class);

        $this->assertSame('long-lived-token', $auth->token());
        $this->assertTrue(Cache::has('bonvoice.api.auth_token'));
    }

    public function test_redact_headers_for_logging_masks_authorization(): void
    {
        $auth = app(BonvoiceAuthentication::class);

        $redacted = $auth->redactHeadersForLogging([
            'Authorization' => 'Token secret-token',
            'Accept' => 'application/json',
        ]);

        $this->assertSame('Token ********', $redacted['Authorization']);
        $this->assertSame('application/json', $redacted['Accept']);
    }
}
