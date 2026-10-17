<?php

namespace Tests\Unit\WalletRefundOpmConversion;

use App\Services\WalletRefundOpmConversion\LegacySpokeWalletRefundOpmClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacySpokeWalletRefundOpmClientTest extends TestCase
{
    public function test_https_base_url_dispatches_with_bearer_token(): void
    {
        Http::fake([
            'https://opm-spoke.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => 'res-1'],
            ], 201),
        ]);

        $payload = app(LegacySpokeWalletRefundOpmClient::class)->post(
            'wallet-opm-conversion-reservations',
            ['amount' => '10.00'],
            [
                'base_url' => 'https://opm-spoke.test',
                'token' => 'bearer-secret',
            ],
        );

        $this->assertSame('res-1', $payload['data']['reservation_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://opm-spoke.test/api/integrations/v1/wallet-opm-conversion-reservations'
                && $request->hasHeader('Authorization', 'Bearer bearer-secret');
        });
    }

    public function test_http_base_url_rejects_before_network_dispatch(): void
    {
        Http::fake();

        try {
            app(LegacySpokeWalletRefundOpmClient::class)->post(
                'wallet-opm-conversion-reservations',
                [],
                [
                    'base_url' => 'http://opm-spoke.test',
                    'token' => 'bearer-secret',
                ],
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Site integration base URL must use HTTPS for wallet OPM conversion.',
                $exception->errors()['conversion'][0],
            );
        }

        Http::assertNothingSent();
    }

    public function test_http_loopback_base_url_rejects_without_bearer_dispatch(): void
    {
        Http::fake();

        try {
            app(LegacySpokeWalletRefundOpmClient::class)->post(
                'wallet-opm-conversion-reservations',
                [],
                [
                    'base_url' => 'http://127.0.0.1:8080',
                    'token' => 'bearer-secret',
                ],
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('HTTPS', $exception->errors()['conversion'][0]);
        }

        Http::assertNothingSent();
    }

    #[DataProvider('invalidBaseUrlProvider')]
    public function test_malformed_or_non_https_base_urls_fail_closed(string $baseUrl): void
    {
        Http::fake();

        try {
            app(LegacySpokeWalletRefundOpmClient::class)->post(
                'wallet-opm-conversion-reservations',
                [],
                [
                    'base_url' => $baseUrl,
                    'token' => 'bearer-secret',
                ],
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidBaseUrlProvider(): array
    {
        return [
            'ftp scheme' => ['ftp://files.test'],
            'missing scheme' => ['opm-spoke.test'],
            'https without host' => ['https://'],
            'whitespace only host path' => ['https:///path-only'],
        ];
    }
}
