@php
    $providerLabels = [
        'auto' => 'Auto (from server)',
        'polling' => 'Polling',
        'ably' => 'Ably',
        'reverb' => 'Reverb',
    ];
    $effectiveProvider = $realtimeHealth['effective_provider'] ?? 'polling';
    $effectiveProviderLabel = $providerLabels[$effectiveProvider] ?? ucfirst($effectiveProvider);
    $transportMode = match ($effectiveProvider) {
        'polling' => 'Polling',
        'ably', 'reverb' => 'WebSocket',
        default => 'Hybrid',
    };
    $reconnectEnabled = collect($realtimeSettings)->firstWhere('key', 'realtime.auto_fallback_polling');
    $reconnectValue = $reconnectEnabled
        ? (is_array(old('settings', [])) && array_key_exists('realtime.auto_fallback_polling', old('settings', []))
            ? filter_var(old('settings.realtime.auto_fallback_polling'), FILTER_VALIDATE_BOOLEAN)
            : filter_var($reconnectEnabled['value'], FILTER_VALIDATE_BOOLEAN))
        : true;

    $connectionSettings = collect($realtimeSettings)->filter(fn ($s) => in_array($s['key'], [
        'realtime.enabled',
        'realtime.connection_status_indicator',
    ]));
    $providerSettings = collect($realtimeSettings)->filter(fn ($s) => in_array($s['key'], [
        'realtime.provider',
    ]));
    $browserSettings = collect($realtimeSettings)->filter(fn ($s) => in_array($s['key'], [
        'realtime.dashboard_live_updates',
        'realtime.desktop_notifications',
    ]));
    $fallbackSettings = collect($realtimeSettings)->filter(fn ($s) => in_array($s['key'], [
        'realtime.auto_fallback_polling',
        'realtime.polling_interval_active_seconds',
        'realtime.polling_interval_idle_seconds',
    ]));
    $debugSettings = collect($realtimeSettings)->filter(fn ($s) => $s['key'] === 'realtime.debug_mode');
@endphp

<x-system-settings.section
    id="realtime-settings-card"
    icon="bi-broadcast"
    title="Realtime"
    description="Dashboard live updates, transport provider, and polling fallback."
>
    <x-system-settings.card
        title="Connection tools"
        description="Admin actions for realtime transport. Live connection status is monitored on Platform."
        class="mb-3"
    >
        <p class="text-muted small mb-3 mb-md-2">
            Configured provider: <code>{{ $effectiveProviderLabel }}</code>
            · Transport: <code>{{ $transportMode }}</code>
            · Fallback: <code>{{ $reconnectValue ? 'Active' : 'Off' }}</code>
        </p>
        <div class="system-settings-action-row">
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-realtime-test
                    data-url="{{ route('admin.system-settings.realtime.test') }}">
                <i class="bi bi-wifi" aria-hidden="true"></i> Test Connection
            </button>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-realtime-force-reconnect
                    data-url="{{ route('admin.system-settings.realtime.force-reconnect') }}">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Force Reconnect
            </button>
            <button type="button"
                    class="btn btn-sm btn-outline-danger"
                    data-realtime-reset-status
                    data-url="{{ route('admin.system-settings.realtime.reset-status') }}">
                Reset Status
            </button>
            <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-outline-secondary">
                Open Platform monitoring
            </a>
        </div>
        <div class="small mt-2 d-none" data-realtime-admin-message aria-live="polite"></div>
    </x-system-settings.card>

    <div class="system-settings-subcards">
        @if($connectionSettings->isNotEmpty())
            <x-system-settings.card title="Realtime Mode" description="Master switches for live dashboard behaviour." class="mb-3">
                <div class="system-settings-rows">
                    @foreach($connectionSettings as $setting)
                        <x-system-settings.setting-row
                            :setting="$setting"
                            :high-impact="$setting['key'] === 'realtime.enabled'"
                            impact-message="Disabling realtime will stop all live dashboard updates. Users will rely on manual refresh."
                            :affected-modules="['Dashboard', 'Operations', 'Notifications']"
                        />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif

        @if($providerSettings->isNotEmpty())
            <x-system-settings.card title="Realtime Provider" description="Transport layer for live events." class="mb-3">
                <div class="system-settings-rows">
                    @foreach($providerSettings as $setting)
                        <x-system-settings.setting-row :setting="$setting" :option-labels="$providerLabels" control-width="14rem" />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif

        @if($browserSettings->isNotEmpty())
            <x-system-settings.card title="Browser Events" description="Client-side live update behaviour." class="mb-3">
                <div class="system-settings-rows">
                    @foreach($browserSettings as $setting)
                        <x-system-settings.setting-row :setting="$setting" />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif

        @if($fallbackSettings->isNotEmpty())
            <x-system-settings.card title="Fallback & Polling" description="HTTP polling intervals when WebSocket is unavailable.">
                <div class="system-settings-rows">
                    @foreach($fallbackSettings as $setting)
                        <x-system-settings.setting-row :setting="$setting" control-width="12rem" />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif

        @if($debugSettings->isNotEmpty())
            <x-system-settings.card title="Advanced" description="Superadmin diagnostic controls." class="mt-3">
                <div class="system-settings-rows">
                    @foreach($debugSettings as $setting)
                        <x-system-settings.setting-row :setting="$setting" />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif
    </div>
</x-system-settings.section>
