@php
    $oldSettings = old('settings', []);
    $currentProfile = is_array($oldSettings) && array_key_exists('performance.profile', $oldSettings)
        ? (string) $oldSettings['performance.profile']
        : $performanceProfile;
    $isManualProfile = $currentProfile === 'manual';
    $profilePresets = collect($performanceProfiles)->mapWithKeys(fn (array $profile, string $key): array => [
        $key => $profile['values'] ?? [],
    ]);

    $profileMeta = [
        'high_performance' => [
            'icon' => 'bi-lightning-charge',
            'impact' => 'Lowest latency',
            'cpu' => 'High',
            'memory' => 'High',
        ],
        'balanced' => [
            'icon' => 'bi-sliders',
            'impact' => 'Recommended balance',
            'cpu' => 'Medium',
            'memory' => 'Medium',
        ],
        'low_resource' => [
            'icon' => 'bi-battery-half',
            'impact' => 'Minimal server load',
            'cpu' => 'Low',
            'memory' => 'Low',
        ],
        'manual' => [
            'icon' => 'bi-gear',
            'impact' => 'Full control',
            'cpu' => 'Custom',
            'memory' => 'Custom',
        ],
    ];
@endphp

<x-system-settings.section
    id="performance-settings-card"
    icon="bi-speedometer2"
    title="Performance"
    description="Runtime performance controls — polling, profiles, and hybrid realtime."
    data-performance-settings
    data-profile-presets='@json($profilePresets)'
>
    <x-system-settings.card
        title="Performance Profile"
        description="Select a preset to auto-populate polling values, or choose Manual to customize."
    >
        <div class="row g-3 system-settings-profile-grid">
            @foreach($performanceProfiles as $profileKey => $profile)
                @php
                    $profileInputId = 'performance_profile_' . $profileKey;
                    $meta = $profileMeta[$profileKey] ?? ['icon' => 'bi-circle', 'impact' => '', 'cpu' => '—', 'memory' => '—'];
                @endphp
                <div class="col-sm-6 col-xl-3">
                    <label @class([
                        'system-settings-profile-card',
                        'system-settings-profile-card--selected' => $currentProfile === $profileKey,
                    ]) for="{{ $profileInputId }}">
                        <input type="radio"
                               name="settings[performance.profile]"
                               value="{{ $profileKey }}"
                               id="{{ $profileInputId }}"
                               class="system-settings-profile-card__input"
                               data-performance-profile-option
                               @checked($currentProfile === $profileKey)>
                        <span class="system-settings-profile-card__icon" aria-hidden="true">
                            <i class="bi {{ $meta['icon'] }}"></i>
                        </span>
                        <span class="system-settings-profile-card__label">
                            {{ $profile['label'] }}
                            @if(! empty($profile['recommended']))
                                <span class="system-settings-profile-card__recommended">Recommended</span>
                            @endif
                        </span>
                        @if(! empty($profile['description']))
                            <span class="system-settings-profile-card__description">{{ $profile['description'] }}</span>
                        @endif
                        <span class="system-settings-profile-card__impact">
                            <span>{{ $meta['impact'] }}</span>
                            <span>CPU {{ $meta['cpu'] }}</span>
                            <span>Memory {{ $meta['memory'] }}</span>
                        </span>
                        <span class="system-settings-profile-card__check" aria-hidden="true">
                            <i class="bi bi-check-lg"></i>
                        </span>
                    </label>
                </div>
            @endforeach
        </div>
        @error('settings.performance.profile')
            <div class="invalid-feedback d-block mt-2">{{ $message }}</div>
        @enderror
    </x-system-settings.card>

    <div @if(! $isManualProfile) hidden @endif data-performance-manual-config id="performance-manual-config">
        <x-system-settings.card
            title="Custom Polling Intervals"
            description="Fine-tune each polling interval. Changes apply after users refresh."
            class="mt-3"
        >
            <div class="system-settings-rows">
                @foreach($performancePollingSettings as $setting)
                    @php
                        $pollingDisabled = ! $isManualProfile || ! empty($setting['disabled']);
                    @endphp
                    <x-system-settings.setting-row
                        :setting="$setting"
                        :readonly="$pollingDisabled"
                        :polling-input="true"
                        control-width="12rem"
                    />
                @endforeach
            </div>
        </x-system-settings.card>
    </div>
</x-system-settings.section>
