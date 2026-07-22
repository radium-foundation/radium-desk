<?php

namespace App\Services\Realtime;

use Ably\AblyRest;
use Ably\Exceptions\AblyException;
use Illuminate\Support\Str;

class RealtimeConnectionTestService
{
    public function __construct(
        private readonly RealtimeRuntimeConfig $runtimeConfig,
    ) {}

    /**
     * @return array{ok: bool, provider: string, message: string}
     */
    public function test(): array
    {
        if (! $this->runtimeConfig->enabled()) {
            return [
                'ok' => true,
                'provider' => 'polling',
                'message' => 'Realtime is disabled; polling mode is active.',
            ];
        }

        $provider = $this->runtimeConfig->provider();

        if ($provider === RealtimeRuntimeConfig::PROVIDER_POLLING) {
            return [
                'ok' => true,
                'provider' => $provider,
                'message' => 'Polling provider selected; no WebSocket credentials required.',
            ];
        }

        if ($provider === RealtimeRuntimeConfig::PROVIDER_ABLY) {
            return $this->testAbly();
        }

        if ($provider === RealtimeRuntimeConfig::PROVIDER_REVERB) {
            return $this->testReverb();
        }

        return [
            'ok' => false,
            'provider' => $provider,
            'message' => 'Unknown realtime provider.',
        ];
    }

    /**
     * @return array{ok: bool, provider: string, message: string}
     */
    private function testAbly(): array
    {
        $key = (string) config('broadcasting.connections.ably.key');

        if ($key === '' || ! str_contains($key, ':')) {
            return [
                'ok' => false,
                'provider' => RealtimeRuntimeConfig::PROVIDER_ABLY,
                'message' => 'ABLY_KEY is missing or invalid in environment configuration.',
            ];
        }

        try {
            $ably = new AblyRest($key);
            $ably->time();

            return [
                'ok' => true,
                'provider' => RealtimeRuntimeConfig::PROVIDER_ABLY,
                'message' => 'Ably REST API reachable with configured credentials.',
            ];
        } catch (AblyException $exception) {
            return [
                'ok' => false,
                'provider' => RealtimeRuntimeConfig::PROVIDER_ABLY,
                'message' => 'Ably connection failed: '.Str::limit($exception->getMessage(), 200),
            ];
        }
    }

    /**
     * @return array{ok: bool, provider: string, message: string}
     */
    private function testReverb(): array
    {
        $key = config('broadcasting.connections.reverb.key');
        $host = config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.port');

        if (! filled($key)) {
            return [
                'ok' => false,
                'provider' => RealtimeRuntimeConfig::PROVIDER_REVERB,
                'message' => 'REVERB_APP_KEY is missing in environment configuration.',
            ];
        }

        return [
            'ok' => true,
            'provider' => RealtimeRuntimeConfig::PROVIDER_REVERB,
            'message' => "Reverb credentials present (host: {$host}:{$port}). Start the Reverb server to verify WebSockets.",
        ];
    }
}
