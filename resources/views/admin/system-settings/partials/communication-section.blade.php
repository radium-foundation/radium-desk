@php
    $communicationCategories = [
        'whatsapp' => ['title' => 'WhatsApp', 'icon' => 'bi-whatsapp', 'color' => '#25D366'],
        'email' => ['title' => 'Email', 'icon' => 'bi-envelope', 'color' => '#3B82F6'],
        'telegram' => ['title' => 'Telegram', 'icon' => 'bi-telegram', 'color' => '#0088CC'],
        'outbox' => ['title' => 'Outbox', 'icon' => 'bi-send', 'color' => '#6366F1'],
    ];
    $hasCommunicationSettings = collect($communicationCategories)->keys()->contains(fn ($key) => ($groupedSettings[$key] ?? collect())->isNotEmpty());
@endphp

@if($hasCommunicationSettings)
    <x-system-settings.section
        id="section-communication"
        icon="bi-plug"
        title="Communication"
        description="Channel integrations and outbound message processing."
    >
        <div class="system-settings-integration-grid">
            @foreach($communicationCategories as $categoryKey => $meta)
                @php
                    $settings = $groupedSettings[$categoryKey] ?? collect();
                @endphp
                @continue($settings->isEmpty())

                @php
                    $primarySetting = $settings->first();
                    $isConnected = $primarySetting
                        ? filter_var(
                            is_array(old('settings', [])) && array_key_exists($primarySetting['key'], old('settings', []))
                                ? old('settings.' . $primarySetting['key'])
                                : $primarySetting['value'],
                            FILTER_VALIDATE_BOOLEAN
                        )
                        : false;
                @endphp

                <div class="system-settings-integration-card"
                     id="category-{{ $categoryKey }}"
                     data-setting-searchable="{{ strtolower($meta['title'] . ' ' . collect($settings)->pluck('label')->join(' ')) }}">
                    <div class="system-settings-integration-card__header">
                        <span class="system-settings-integration-card__logo" style="--integration-color: {{ $meta['color'] }}" aria-hidden="true">
                            <i class="bi {{ $meta['icon'] }}"></i>
                        </span>
                        <div class="system-settings-integration-card__identity">
                            <h4 class="system-settings-integration-card__title">{{ $meta['title'] }}</h4>
                            <span @class([
                                'system-settings-status-pill system-settings-status-pill--sm',
                                'system-settings-status-pill--success' => $isConnected,
                                'system-settings-status-pill--neutral' => ! $isConnected,
                            ])>
                                {{ $isConnected ? 'Connected' : 'Disabled' }}
                            </span>
                        </div>
                    </div>

                    <div class="system-settings-rows">
                        @foreach($settings as $setting)
                            <x-system-settings.setting-row
                                :setting="$setting"
                                :high-impact="in_array($setting['key'], ['outbox.processor_enabled', 'whatsapp.api_enabled', 'email.api_enabled', 'telegram.api_enabled'], true)"
                                :impact-message="'Disabling ' . $setting['label'] . ' may affect outbound ' . $meta['title'] . ' delivery.'"
                                :affected-modules="[$meta['title'], 'Outbox']"
                            />
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-system-settings.section>
@endif
