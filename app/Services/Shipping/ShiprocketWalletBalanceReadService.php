<?php

namespace App\Services\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\ShiprocketWalletBalanceStatus;
use App\Models\User;
use App\Services\Shipping\Data\ShiprocketWalletBalancePresentation;
use App\Services\Shipping\Data\ShiprocketWalletBalanceResult;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\Money\WalletMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cached, read-only Shiprocket wallet balance for Hardware operators.
 * Informational only — never blocks shipping actions.
 */
final class ShiprocketWalletBalanceReadService
{
    private const CACHE_PREFIX = 'shipping:shiprocket:wallet-balance:';

    private const LAST_KNOWN_PREFIX = 'shipping:shiprocket:wallet-balance:last-known:';

    public function __construct(
        private readonly ShiprocketGateway $gateway,
    ) {}

    public function canView(?User $user): bool
    {
        return HardwareFulfilmentAccess::allows($user);
    }

    public function presentFor(?User $user): ?ShiprocketWalletBalancePresentation
    {
        if (! $this->canView($user)) {
            return null;
        }

        if (! $this->providerConfigured()) {
            return new ShiprocketWalletBalancePresentation(
                status: ShiprocketWalletBalanceStatus::Unknown,
            );
        }

        $cached = $this->readCachedPayload();
        if ($cached !== null && ! $this->isExpired($cached)) {
            return $this->presentationFromPayload($cached, stale: false);
        }

        $result = $this->gateway->getWalletBalance();
        if ($result->status === 'available' && $result->balanceAmount !== null) {
            $payload = $this->payloadFromResult($result);
            $this->storeCachedPayload($payload);
            $this->storeLastKnownPayload($payload);

            return $this->presentationFromPayload($payload, stale: false);
        }

        $lastKnown = $this->readLastKnownPayload();
        if ($lastKnown !== null) {
            return $this->presentationFromPayload($lastKnown, stale: true);
        }

        return $this->presentationFromFailure($result);
    }

    public function ttlSeconds(): int
    {
        return max(60, (int) config('shipping.wallet_balance_ttl_seconds', 900));
    }

    public function lowThreshold(): string
    {
        $normalized = WalletMoney::normalize((string) config('shipping.wallet_balance_low_threshold', '1000.00'));

        return $normalized ?? '1000.00';
    }

    private function providerConfigured(): bool
    {
        if (! (bool) config('shipping.enabled')) {
            return false;
        }

        if ((string) config('shipping.provider') !== 'shiprocket') {
            return false;
        }

        if (! (bool) config('shipping.http_enabled')) {
            return false;
        }

        $email = trim((string) config('shipping.api_email'));
        $password = trim((string) config('shipping.api_password'));

        return $email !== '' && $password !== '';
    }

    /**
     * @return array{balance_amount: string, checked_at: int}|null
     */
    private function readCachedPayload(): ?array
    {
        return $this->readPayload(self::CACHE_PREFIX.$this->credentialFingerprint());
    }

    /**
     * @return array{balance_amount: string, checked_at: int}|null
     */
    private function readLastKnownPayload(): ?array
    {
        return $this->readPayload(self::LAST_KNOWN_PREFIX.$this->credentialFingerprint());
    }

    /**
     * @return array{balance_amount: string, checked_at: int}|null
     */
    private function readPayload(string $key): ?array
    {
        try {
            $payload = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $amount = WalletMoney::normalize($payload['balance_amount'] ?? null);
        $checkedAt = (int) ($payload['checked_at'] ?? 0);
        if ($amount === null || $checkedAt <= 0) {
            return null;
        }

        return [
            'balance_amount' => $amount,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * @param  array{balance_amount: string, checked_at: int}  $payload
     */
    private function isExpired(array $payload): bool
    {
        return (now()->getTimestamp() - $payload['checked_at']) >= $this->ttlSeconds();
    }

    /**
     * @param  array{balance_amount: string, checked_at: int}  $payload
     */
    private function storeCachedPayload(array $payload): void
    {
        try {
            Cache::put(
                self::CACHE_PREFIX.$this->credentialFingerprint(),
                $payload,
                $this->ttlSeconds(),
            );
        } catch (Throwable) {
            // Best-effort cache only.
        }
    }

    /**
     * @param  array{balance_amount: string, checked_at: int}  $payload
     */
    private function storeLastKnownPayload(array $payload): void
    {
        try {
            Cache::put(
                self::LAST_KNOWN_PREFIX.$this->credentialFingerprint(),
                $payload,
                now()->addDay(),
            );
        } catch (Throwable) {
            // Best-effort cache only.
        }
    }

    private function payloadFromResult(ShiprocketWalletBalanceResult $result): array
    {
        return [
            'balance_amount' => WalletMoney::normalize($result->balanceAmount) ?? '0.00',
            'checked_at' => now()->getTimestamp(),
        ];
    }

    /**
     * @param  array{balance_amount: string, checked_at: int}  $payload
     */
    private function presentationFromPayload(array $payload, bool $stale): ShiprocketWalletBalancePresentation
    {
        return new ShiprocketWalletBalancePresentation(
            status: $this->statusForAmount($payload['balance_amount']),
            balanceAmount: $payload['balance_amount'],
            checkedAt: Carbon::createFromTimestamp($payload['checked_at']),
            isStale: $stale,
        );
    }

    private function presentationFromFailure(ShiprocketWalletBalanceResult $result): ShiprocketWalletBalancePresentation
    {
        return new ShiprocketWalletBalancePresentation(
            status: match ($result->failureKind) {
                'auth' => ShiprocketWalletBalanceStatus::AuthError,
                'provider', 'disabled' => ShiprocketWalletBalanceStatus::ProviderError,
                default => ShiprocketWalletBalanceStatus::Unknown,
            },
        );
    }

    private function statusForAmount(string $amount): ShiprocketWalletBalanceStatus
    {
        if (bccomp($amount, '0', WalletMoney::SCALE) === 0) {
            return ShiprocketWalletBalanceStatus::Zero;
        }

        if (bccomp($amount, $this->lowThreshold(), WalletMoney::SCALE) <= 0) {
            return ShiprocketWalletBalanceStatus::Low;
        }

        return ShiprocketWalletBalanceStatus::Available;
    }

    private function credentialFingerprint(): string
    {
        $email = trim((string) config('shipping.api_email'));
        $password = (string) config('shipping.api_password');

        return hash('sha256', $email."\0".$password);
    }
}
