<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use Illuminate\Http\Request;

/**
 * Desk → Box uses the verified ingest HMAC: hex HMAC-SHA256 of `{timestamp}{rawBody}`.
 */
final class HardwareFulfilmentCallbackSigner
{
    public const HEADER_CHANNEL = 'X-Desk-Channel';

    public const HEADER_TIMESTAMP = 'X-Desk-Timestamp';

    public const HEADER_SIGNATURE = 'X-Desk-Signature';

    public function __construct(
        private readonly ChannelIngestAuthenticator $hmac,
    ) {}

    /**
     * @return array{channel: string, timestamp: string, signature: string, headers: array<string, string>}
     */
    public function sign(string $rawBody, ?int $timestamp = null): array
    {
        $secret = $this->secret();
        if ($secret === null) {
            throw new HardwareFulfilmentCallbackNonRetryableException('Desk callback secret is not configured. No callback was sent.');
        }

        $unix = (string) ($timestamp ?? time());
        $signature = $this->hmac->signature($unix, $rawBody, $secret);

        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'timestamp' => $unix,
            'signature' => $signature,
            'headers' => [
                self::HEADER_CHANNEL => StatutoryInvoiceChannel::RadiumBoxCom->value,
                self::HEADER_TIMESTAMP => $unix,
                self::HEADER_SIGNATURE => $signature,
            ],
        ];
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string, replay: bool}
     */
    public function verify(Request $request): array
    {
        $secret = $this->secret();
        $timestamp = trim((string) $request->header(self::HEADER_TIMESTAMP, ''));
        $signature = trim((string) $request->header(self::HEADER_SIGNATURE, ''));
        $channel = trim((string) $request->header(self::HEADER_CHANNEL, ''));

        if ($secret === null || $timestamp === '' || $signature === '' || $channel === '') {
            return ['ok' => false, 'error' => 'Callback authentication failed.', 'replay' => false];
        }

        if ($channel !== StatutoryInvoiceChannel::RadiumBoxCom->value || ! ctype_digit($timestamp)) {
            return ['ok' => false, 'error' => 'Callback authentication failed.', 'replay' => false];
        }

        $window = (int) config('hardware_fulfilment.callback.replay_window_seconds', 300);
        if (abs(time() - (int) $timestamp) > $window) {
            return ['ok' => false, 'error' => 'Callback timestamp is outside the replay window.', 'replay' => true];
        }

        $expected = $this->hmac->signature($timestamp, $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return ['ok' => false, 'error' => 'Callback authentication failed.', 'replay' => false];
        }

        return ['ok' => true];
    }

    public function secret(): ?string
    {
        $raw = config('hardware_fulfilment.callback.secret');
        if (! is_string($raw)) {
            return null;
        }

        $secret = trim($raw);

        return $secret === '' ? null : $secret;
    }
}
