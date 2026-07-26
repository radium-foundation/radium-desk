@props([
    'section',
    'variant' => 'default',
])

@php
    $key = $section['key'] ?? '';
    $label = $section['label'] ?? '';
    $icon = $section['icon'] ?? 'bi-grid';
    $cards = $section['cards'] ?? [];
    $sectionId = $key === 'platform_health' ? 'platform-health' : 'platform-section-'.$key;
    $isExecutive = $variant === 'executive';
    $isHealth = $variant === 'health';
@endphp

<x-settings-center.section
    :id="$sectionId"
    :icon="$icon"
    :title="$label"
    :description="null"
    data-platform-section="{{ $key }}"
    data-platform-searchable="{{ strtolower($label) }}"
    @class([
        'settings-center-platform__section',
        'settings-center-platform__section--executive' => $isExecutive,
        'settings-center-platform__section--health' => $isHealth,
    ])
>
    <div @class([
        'settings-center-platform__cards',
        'settings-center-platform__cards--executive' => $isExecutive,
        'row g-3' => ! $isExecutive,
    ])>
        @foreach($cards as $card)
            <div
                @class([
                    'settings-center-platform__card-slot',
                    $card->columnClass() => ! $isExecutive,
                ])
                data-platform-card-slot="{{ $card->key }}"
                data-platform-searchable="{{ strtolower($card->title.' '.$label) }}"
            >
                <x-platform.card :card="$card" />
            </div>
        @endforeach
    </div>
</x-settings-center.section>
