<?php

namespace Tests\Unit\ChannelIngest;

use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use Illuminate\Http\Request;
use Tests\TestCase;

class ChannelIngestAuthenticatorTest extends TestCase
{
    public function test_valid_hmac_authenticates_the_channel(): void
    {
        config(['channel_ingest.secrets.rdservice_in' => 'channel-secret']);
        $body = '{"channel":"rdservice_in"}';
        $timestamp = (string) time();
        $signature = (new ChannelIngestAuthenticator)->signature($timestamp, $body, 'channel-secret');

        $request = Request::create('/api/v1/channel-orders', 'POST', [], [], [], [
            'HTTP_X_DESK_CHANNEL' => 'rdservice_in',
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => $signature,
        ], $body);

        $result = (new ChannelIngestAuthenticator)->authenticate($request);

        $this->assertTrue($result['ok']);
        $this->assertSame('rdservice_in', $result['channel']->value);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        config(['channel_ingest.secrets.rdservice_in' => 'channel-secret']);
        $request = Request::create('/api/v1/channel-orders', 'POST', [], [], [], [
            'HTTP_X_DESK_CHANNEL' => 'rdservice_in',
            'HTTP_X_DESK_TIMESTAMP' => (string) time(),
            'HTTP_X_DESK_SIGNATURE' => 'nope',
        ], '{}');

        $result = (new ChannelIngestAuthenticator)->authenticate($request);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http_status']);
    }
}
