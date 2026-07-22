<?php

namespace App\Services\Realtime;

use App\Services\Realtime\RealtimeConnectionStatusService;
use App\Services\SystemSettingsService;
use Illuminate\Support\Str;

class RealtimeRuntimeConfig
{
    public const PROVIDER_AUTO = 'auto';

    public const PROVIDER_POLLING = 'polling';

    public const PROVIDER_ABLY = 'ably';

    public const PROVIDER_REVERB = 'reverb';

    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly RealtimeConnectionStatusService $connectionStatus,
    ) {}

    public function enabled(): bool
    {
        return $this->systemSettings->getBool('realtime.enabled', true);
    }

    public function provider(): string
    {
        $provider = (string) $this->systemSettings->get('realtime.provider', self::PROVIDER_AUTO);

        if ($provider === self::PROVIDER_AUTO) {
            return $this->providerFromBroadcastDriver();
        }

        return $provider;
    }

    public function dashboardLiveUpdatesEnabled(): bool
    {
        return $this->systemSettings->getBool('realtime.dashboard_live_updates', true);
    }

    public function desktopNotificationsEnabled(): bool
    {
        return $this->systemSettings->getBool('realtime.desktop_notifications', true);
    }

    public function autoFallbackPolling(): bool
    {
        return $this->systemSettings->getBool('realtime.auto_fallback_polling', true);
    }

    public function pollingIntervalActiveMs(): int
    {
        return $this->secondsToMs('realtime.polling_interval_active_seconds', 20);
    }

    public function pollingIntervalIdleMs(): int
    {
        return $this->secondsToMs('realtime.polling_interval_idle_seconds', 60);
    }

    public function connectionStatusIndicatorEnabled(): bool
    {
        return $this->systemSettings->getBool('realtime.connection_status_indicator', false);
    }

    public function debugModeEnabled(): bool
    {
        return $this->systemSettings->getBool('realtime.debug_mode', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function forDashboardBlade(): array
    {
        $dashboardLiveUpdatesEnabled = $this->dashboardLiveUpdatesEnabled();

        return [
            'dashboardLiveMode' => $this->dashboardTransportMode(),
            'dashboardPollIntervalActiveMs' => $this->pollingIntervalActiveMs(),
            'dashboardPollIntervalIdleMs' => $this->pollingIntervalIdleMs(),
            'dashboardLiveUpdatesEnabled' => $dashboardLiveUpdatesEnabled,
            'desktopNotificationsEnabled' => $this->desktopNotificationsEnabled(),
            'connectionStatusIndicatorEnabled' => $this->connectionStatusIndicatorEnabled(),
            'debugModeEnabled' => $this->debugModeEnabled(),
            'realtimeProvider' => $this->provider(),
            'echoConfigured' => $this->echoConfigured(),
            'echoBroadcaster' => $this->echoBroadcaster(),
            'echoKey' => $this->echoKey(),
            'echoHost' => $this->echoHost(),
            'echoPort' => $this->echoPort(),
            'echoScheme' => $this->echoScheme(),
            'realtimeStatusUrl' => route('dashboard.realtime.connection-status'),
            'realtimeForceReconnectAt' => $this->connectionStatus->consumeForceReconnectRequest(),
        ];
    }

    public function dashboardTransportMode(): string
    {
        if (! $this->enabled() || $this->provider() === self::PROVIDER_POLLING || ! $this->dashboardLiveUpdatesEnabled()) {
            return 'poll';
        }

        if (! $this->autoFallbackPolling()) {
            return 'reverb';
        }

        return 'auto';
    }

    public function echoBroadcaster(): ?string
    {
        return match ($this->provider()) {
            self::PROVIDER_ABLY => 'pusher',
            self::PROVIDER_REVERB => 'reverb',
            default => null,
        };
    }

    public function echoKey(): ?string
    {
        return match ($this->provider()) {
            self::PROVIDER_ABLY => $this->ablyPublicKey(),
            self::PROVIDER_REVERB => config('broadcasting.connections.reverb.key'),
            default => null,
        };
    }

    public function echoHost(): ?string
    {
        return match ($this->provider()) {
            self::PROVIDER_ABLY => 'realtime-pusher.ably.io',
            self::PROVIDER_REVERB => config('broadcasting.connections.reverb.options.host'),
            default => null,
        };
    }

    public function echoPort(): ?int
    {
        return match ($this->provider()) {
            self::PROVIDER_ABLY => 443,
            self::PROVIDER_REVERB => (int) config('broadcasting.connections.reverb.options.port'),
            default => null,
        };
    }

    public function echoScheme(): string
    {
        return match ($this->provider()) {
            self::PROVIDER_ABLY => 'https',
            self::PROVIDER_REVERB => (string) config('broadcasting.connections.reverb.options.scheme', 'https'),
            default => 'https',
        };
    }

    public function echoConfigured(): bool
    {
        return $this->shouldInitializeEcho() && filled($this->echoKey());
    }

    public function shouldInitializeEcho(): bool
    {
        return $this->enabled()
            && in_array($this->provider(), [self::PROVIDER_ABLY, self::PROVIDER_REVERB], true)
            && $this->echoCredentialsConfigured();
    }

    private function providerFromBroadcastDriver(): string
    {
        return match ((string) config('broadcasting.default')) {
            'ably' => self::PROVIDER_ABLY,
            'reverb' => self::PROVIDER_REVERB,
            default => self::PROVIDER_POLLING,
        };
    }

    private function echoCredentialsConfigured(): bool
    {
        return match ($this->provider()) {
            self::PROVIDER_ABLY => filled(config('broadcasting.connections.ably.key')),
            self::PROVIDER_REVERB => filled(config('broadcasting.connections.reverb.key')),
            default => false,
        };
    }

    private function ablyPublicKey(): ?string
    {
        $key = (string) config('broadcasting.connections.ably.key');

        if ($key === '') {
            return null;
        }

        return Str::before($key, ':') ?: null;
    }

    private function secondsToMs(string $key, int $defaultSeconds): int
    {
        $seconds = (int) $this->systemSettings->get($key, $defaultSeconds);

        return max(1, $seconds) * 1000;
    }
}
