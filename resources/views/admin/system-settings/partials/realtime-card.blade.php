@php
    $providerLabels = [
        'auto' => 'Auto (from server)',
        'polling' => 'Polling',
        'ably' => 'Ably',
        'reverb' => 'Reverb',
    ];
    $connectionStatus = $realtimeHealth['connection_status'] ?? 'unknown';
    $connectionLabel = match ($connectionStatus) {
        'connected' => 'Connected',
        'connecting' => 'Connecting',
        'polling' => 'Polling',
        'offline' => 'Offline',
        'disconnected' => 'Disconnected',
        default => 'Unknown',
    };
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
        title="Connection"
        description="Live snapshot from the most recent dashboard client report."
        class="mb-3"
    >
        <div class="system-settings-connection-status">
            <div class="system-settings-connection-status__hero">
                <span @class([
                    'system-settings-status-pill',
                    'system-settings-status-pill--success' => $connectionStatus === 'connected',
                    'system-settings-status-pill--info' => $connectionStatus === 'connecting',
                    'system-settings-status-pill--warning' => $connectionStatus === 'polling',
                    'system-settings-status-pill--neutral' => in_array($connectionStatus, ['unknown', 'offline'], true),
                    'system-settings-status-pill--danger' => $connectionStatus === 'disconnected',
                ])>
                    <span class="system-settings-status-pill__dot" aria-hidden="true"></span>
                    {{ $connectionLabel }}
                </span>
                <div class="system-settings-connection-status__details">
                    <span><strong>Provider</strong> {{ $effectiveProviderLabel }}</span>
                    <span><strong>Transport</strong> {{ $transportMode }}</span>
                    <span><strong>Fallback</strong> {{ $reconnectValue ? 'Active' : 'Off' }}</span>
                </div>
            </div>

            <div class="system-settings-mini-metrics">
                <div class="system-settings-mini-metric">
                    <span class="system-settings-mini-metric__label">Polling active</span>
                    <span class="system-settings-mini-metric__value">{{ ! empty($realtimeHealth['polling_active']) ? 'Yes' : 'No' }}</span>
                </div>
                <div class="system-settings-mini-metric">
                    <span class="system-settings-mini-metric__label">Last connected</span>
                    <span class="system-settings-mini-metric__value">
                        @if(! empty($realtimeHealth['last_connected_at']))
                            {{ \Illuminate\Support\Carbon::parse($realtimeHealth['last_connected_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}
                        @else
                            Never
                        @endif
                    </span>
                </div>
            </div>

            @if(! empty($realtimeHealth['last_error']) || ! empty($realtimeHealth['last_disconnect_reason']))
                <div class="system-settings-status-alerts mt-3">
                    @if(! empty($realtimeHealth['last_error']))
                        <div class="system-settings-status-alert system-settings-status-alert--error">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <span>{{ $realtimeHealth['last_error'] }}</span>
                        </div>
                    @endif
                    @if(! empty($realtimeHealth['last_disconnect_reason']))
                        <div class="system-settings-status-alert">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span>{{ $realtimeHealth['last_disconnect_reason'] }}</span>
                        </div>
                    @endif
                </div>
            @endif

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
            </div>
            <div class="small mt-2 d-none" data-realtime-admin-message aria-live="polite"></div>
        </div>
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
