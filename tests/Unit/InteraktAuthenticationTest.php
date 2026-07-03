<?php

namespace Tests\Unit;

use App\Services\Interakt\InteraktAuthentication;
use Tests\TestCase;

class InteraktAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['interakt.api_key' => 'test-interakt-key']);
    }

    public function test_headers_use_literal_basic_secret_key(): void
    {
        $headers = app(InteraktAuthentication::class)->headers();

        $this->assertSame('Basic test-interakt-key', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Accept']);
    }

    public function test_headers_do_not_base64_encode_secret_key(): void
    {
        $headers = app(InteraktAuthentication::class)->headers();

        $this->assertNotSame(
            'Basic '.base64_encode('test-interakt-key:'),
            $headers['Authorization'],
        );
        $this->assertStringNotContainsString(':', substr($headers['Authorization'], strlen('Basic ')));
    }

    public function test_redact_headers_for_logging_masks_authorization(): void
    {
        $authentication = app(InteraktAuthentication::class);

        $redacted = $authentication->redactHeadersForLogging($authentication->headers());

        $this->assertSame('Basic ********', $redacted['Authorization']);
        $this->assertSame('application/json', $redacted['Accept']);
    }
}
