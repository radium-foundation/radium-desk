<?php

namespace App\Services\Realtime;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RealtimeConnectionStatusService
{
    private const CACHE_KEY = 'realtime.connection_status';

    private const FORCE_RECONNECT_CACHE_KEY = 'realtime.force_reconnect_requested_at';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $snapshot = Cache::get(self::CACHE_KEY);

        return is_array($snapshot) ? $snapshot : [
            'status' => 'unknown',
            'provider' => null,
            'polling_active' => false,
            'last_connected_at' => null,
            'last_disconnected_at' => null,
            'last_disconnect_reason' => null,
            'last_error' => null,
            'reported_at' => null,
            'reported_by_user_id' => null,
        ];
    }

    public function reset(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function requestForceReconnect(): void
    {
        Cache::put(self::FORCE_RECONNECT_CACHE_KEY, now()->toIso8601String(), now()->addMinutes(5));
    }

    public function forceReconnectRequestedAt(): ?string
    {
        $value = Cache::get(self::FORCE_RECONNECT_CACHE_KEY);

        return is_string($value) ? $value : null;
    }

    public function clearForceReconnectRequest(): void
    {
        Cache::forget(self::FORCE_RECONNECT_CACHE_KEY);
    }

    public function consumeForceReconnectRequest(): ?string
    {
        $requestedAt = $this->forceReconnectRequestedAt();

        if ($requestedAt !== null) {
            $this->clearForceReconnectRequest();
        }

        return $requestedAt;
    }

    public function recordConnecting(User $user, string $provider): void
    {
        $snapshot = $this->snapshot();

        $this->store([
            'status' => 'connecting',
            'provider' => $provider,
            'polling_active' => false,
            'last_connected_at' => $snapshot['last_connected_at'] ?? null,
            'last_disconnected_at' => $snapshot['last_disconnected_at'] ?? null,
            'last_disconnect_reason' => $snapshot['last_disconnect_reason'] ?? null,
            'last_error' => $snapshot['last_error'] ?? null,
            'reported_at' => now()->toIso8601String(),
            'reported_by_user_id' => $user->id,
        ]);
    }

    public function recordPolling(User $user, string $provider, ?string $reason = null): void
    {
        $snapshot = $this->snapshot();
        $now = now()->toIso8601String();

        $this->store([
            'status' => 'polling',
            'provider' => $provider,
            'polling_active' => true,
            'last_connected_at' => $snapshot['last_connected_at'] ?? null,
            'last_disconnected_at' => $snapshot['last_disconnected_at'] ?? null,
            'last_disconnect_reason' => $reason ?? $snapshot['last_disconnect_reason'] ?? null,
            'last_error' => $snapshot['last_error'] ?? null,
            'reported_at' => $now,
            'reported_by_user_id' => $user->id,
        ]);
    }

    public function recordConnected(User $user, string $provider): void
    {
        $now = now()->toIso8601String();

        $this->store([
            'status' => 'connected',
            'provider' => $provider,
            'polling_active' => false,
            'last_connected_at' => $now,
            'last_disconnected_at' => null,
            'last_disconnect_reason' => null,
            'last_error' => null,
            'reported_at' => $now,
            'reported_by_user_id' => $user->id,
        ]);
    }

    public function recordDisconnected(User $user, string $provider, ?string $reason = null): void
    {
        $snapshot = $this->snapshot();
        $now = now()->toIso8601String();

        $this->store([
            'status' => 'disconnected',
            'provider' => $provider,
            'polling_active' => false,
            'last_connected_at' => $snapshot['last_connected_at'] ?? null,
            'last_disconnected_at' => $now,
            'last_disconnect_reason' => $reason,
            'last_error' => $snapshot['last_error'] ?? null,
            'reported_at' => $now,
            'reported_by_user_id' => $user->id,
        ]);
    }

    public function recordError(User $user, string $provider, string $message): void
    {
        $snapshot = $this->snapshot();
        $now = now()->toIso8601String();
        $reason = Str::limit($message, 500);

        $this->store([
            'status' => 'disconnected',
            'provider' => $provider,
            'polling_active' => false,
            'last_connected_at' => $snapshot['last_connected_at'] ?? null,
            'last_disconnected_at' => $now,
            'last_disconnect_reason' => $reason,
            'last_error' => $reason,
            'reported_at' => $now,
            'reported_by_user_id' => $user->id,
        ]);
    }

    public function recordOffline(User $user, string $provider): void
    {
        $snapshot = $this->snapshot();

        $this->store([
            'status' => 'offline',
            'provider' => $provider,
            'polling_active' => (bool) ($snapshot['polling_active'] ?? false),
            'last_connected_at' => $snapshot['last_connected_at'] ?? null,
            'last_disconnected_at' => now()->toIso8601String(),
            'last_disconnect_reason' => 'Browser offline',
            'last_error' => $snapshot['last_error'] ?? null,
            'reported_at' => now()->toIso8601String(),
            'reported_by_user_id' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function store(array $snapshot): void
    {
        Cache::put(self::CACHE_KEY, $snapshot, now()->addDays(7));
    }
}
