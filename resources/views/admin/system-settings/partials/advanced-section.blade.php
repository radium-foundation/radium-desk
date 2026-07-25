@php
    $systemSettings = $groupedSettings['system'] ?? collect();
    $hybridSettings = collect($performanceHybridRealtimeSettings);
    $hasAdvanced = $systemSettings->isNotEmpty() || $hybridSettings->isNotEmpty();
@endphp

@if($hasAdvanced)
    <x-system-settings.section
        id="section-advanced"
        icon="bi-gear"
        title="Advanced"
        description="Hybrid realtime features and platform diagnostic controls."
    >
        @if($hybridSettings->isNotEmpty())
            <x-system-settings.card
                title="Hybrid Realtime"
                description="Opt-in Reverb features for live updates. Polling remains the safety net when disabled."
                class="mb-3"
            >
                <div class="system-settings-rows">
                    @foreach($hybridSettings as $setting)
                        <x-system-settings.setting-row :setting="$setting" />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif

        @if($systemSettings->isNotEmpty())
            <x-system-settings.card
                title="System Diagnostics"
                description="Core platform behaviour and troubleshooting controls."
            >
                <div class="system-settings-rows">
                    @foreach($systemSettings as $setting)
                        <x-system-settings.setting-row :setting="$setting" />
                    @endforeach
                </div>
            </x-system-settings.card>
        @endif
    </x-system-settings.section>
@endif
