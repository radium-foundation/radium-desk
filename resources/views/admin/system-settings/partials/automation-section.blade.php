@php
    $automationCategories = [
        'automation' => ['title' => 'Scheduler', 'icon' => 'bi-calendar-check', 'description' => 'Waiting-state automation execution.'],
        'ira' => ['title' => 'IRA', 'icon' => 'bi-stars', 'description' => 'Intelligent response assistant.'],
    ];
    $hasAutomationSettings = collect($automationCategories)->keys()->contains(fn ($key) => ($groupedSettings[$key] ?? collect())->isNotEmpty());
@endphp

@if($hasAutomationSettings)
    <x-system-settings.section
        id="section-automation"
        icon="bi-robot"
        title="Automation"
        description="Scheduler and intelligent automation execution controls."
    >
        <div class="system-settings-automation-grid">
            @foreach($automationCategories as $categoryKey => $meta)
                @php
                    $settings = $groupedSettings[$categoryKey] ?? collect();
                @endphp
                @continue($settings->isEmpty())

                <div class="system-settings-automation-card" id="category-{{ $categoryKey }}" data-setting-searchable="{{ strtolower($meta['title'] . ' ' . $meta['description']) }}">
                    <div class="system-settings-automation-card__header">
                        <span class="system-settings-automation-card__icon" aria-hidden="true">
                            <i class="bi {{ $meta['icon'] }}"></i>
                        </span>
                        <div>
                            <h4 class="system-settings-automation-card__title">{{ $meta['title'] }}</h4>
                            <p class="system-settings-automation-card__description">{{ $meta['description'] }}</p>
                        </div>
                    </div>
                    <div class="system-settings-rows">
                        @foreach($settings as $setting)
                            <x-system-settings.setting-row :setting="$setting" />
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-system-settings.section>
@endif
