@php
    $channelSettings = $groupedSettings['notifications'] ?? collect();
    $channelMeta = [
        'notifications.whatsapp.enabled' => ['icon' => 'bi-whatsapp', 'channel' => 'WhatsApp'],
        'notifications.email.enabled' => ['icon' => 'bi-envelope', 'channel' => 'Email'],
        'notifications.desktop.enabled' => ['icon' => 'bi-window', 'channel' => 'Browser'],
        'notifications.telegram.enabled' => ['icon' => 'bi-telegram', 'channel' => 'Telegram'],
    ];
@endphp

<x-system-settings.section
    id="category-notifications"
    icon="bi-bell"
    title="Notifications"
    description="Channel dispatch controls and operator notification delivery preferences."
>
    @if($channelSettings->isNotEmpty())
        <div class="system-settings-channel-grid mb-3">
            @foreach($channelSettings as $setting)
                @php
                    $meta = $channelMeta[$setting['key']] ?? ['icon' => 'bi-bell', 'channel' => $setting['label']];
                    $isEnabled = filter_var(
                        is_array(old('settings', [])) && array_key_exists($setting['key'], old('settings', []))
                            ? old('settings.' . $setting['key'])
                            : $setting['value'],
                        FILTER_VALIDATE_BOOLEAN
                    );
                @endphp
                <div class="system-settings-channel-card" data-setting-searchable="{{ strtolower($meta['channel'] . ' ' . $setting['label'] . ' ' . ($setting['description'] ?? '')) }}">
                    <div class="system-settings-channel-card__header">
                        <div class="system-settings-channel-card__identity">
                            <span class="system-settings-channel-card__icon" aria-hidden="true">
                                <i class="bi {{ $meta['icon'] }}"></i>
                            </span>
                            <div>
                                <h4 class="system-settings-channel-card__title">{{ $meta['channel'] }}</h4>
                                <span @class([
                                    'system-settings-status-pill system-settings-status-pill--sm',
                                    'system-settings-status-pill--success' => $isEnabled,
                                    'system-settings-status-pill--neutral' => ! $isEnabled,
                                ])>
                                    {{ $isEnabled ? 'Active' : 'Disabled' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <p class="system-settings-channel-card__description">{{ $setting['description'] }}</p>
                    <div class="system-settings-channel-card__control">
                        <x-system-settings.setting-row
                            :setting="$setting"
                            :high-impact="true"
                            impact-message="Disabling this channel will stop all outbound notifications through {{ $meta['channel'] }}."
                            :affected-modules="['Notification Dispatcher', $meta['channel']]"
                        />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-system-settings.card
        title="Delivery Preferences"
        description="Configure how realtime notifications are delivered to operators."
    >
        <div class="system-settings-rows">
            @foreach($performanceNotificationSettings as $setting)
                <x-system-settings.setting-row :setting="$setting" control-width="14rem" />
            @endforeach
        </div>
    </x-system-settings.card>
</x-system-settings.section>
