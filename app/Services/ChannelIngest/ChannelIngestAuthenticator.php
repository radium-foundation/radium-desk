<?php

namespace App\Services\ChannelIngest;

use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Http\Request;

final class ChannelIngestAuthenticator
{
    public const ERROR_UNAUTHORIZED = 'Channel ingest authentication failed.';

    public const ERROR_REPLAY = 'Channel ingest request timestamp is outside the replay window.';

    /**
     * HMAC-SHA256 hex of `{unixTimestamp}{rawBody}`, matching the Cashfree
     * timestamp-concatenation pattern already used in this repository.
     */
    public function signature(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.$rawBody, $secret);
    }

    /**
     * @return array{ok: true, channel: StatutoryInvoiceChannel}|array{ok: false, error: string, replay: bool, http_status: int}
     */
    public function authenticate(Request $request): array
    {
        $channelHeader = trim((string) $request->header('X-Desk-Channel', ''));
        $timestamp = trim((string) $request->header('X-Desk-Timestamp', ''));
        $signature = trim((string) $request->header('X-Desk-Signature', ''));

        $channel = StatutoryInvoiceChannel::tryFrom($channelHeader);
        if ($channel === null || $channel === StatutoryInvoiceChannel::DeskPos) {
            return $this->deny(self::ERROR_UNAUTHORIZED, false, 401);
        }

        $secret = $this->secretFor($channel);
        if ($secret === null) {
            return $this->deny(self::ERROR_UNAUTHORIZED, false, 401);
        }

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return $this->deny(self::ERROR_UNAUTHORIZED, false, 401);
        }

        $window = (int) config('channel_ingest.replay_window_seconds', 300);
        $skew = abs(time() - (int) $timestamp);
        if ($skew > $window) {
            return $this->deny(self::ERROR_REPLAY, true, 401);
        }

        $expected = $this->signature($timestamp, $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->deny(self::ERROR_UNAUTHORIZED, false, 401);
        }

        return ['ok' => true, 'channel' => $channel];
    }

    public function secretFor(StatutoryInvoiceChannel $channel): ?string
    {
        $raw = config('channel_ingest.secrets.'.$channel->value);
        if (! is_string($raw)) {
            return null;
        }

        $secret = trim($raw);

        return $secret === '' ? null : $secret;
    }

    /**
     * @return array{ok: false, error: string, replay: bool, http_status: int}
     */
    private function deny(string $error, bool $replay, int $status): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'replay' => $replay,
            'http_status' => $status,
        ];
    }
}
