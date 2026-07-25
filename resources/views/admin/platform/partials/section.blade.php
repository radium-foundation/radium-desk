@props([
    'section',
])

@php
    $key = $section['key'] ?? '';
    $label = $section['label'] ?? '';
    $description = $section['description'] ?? null;
    $icon = $section['icon'] ?? 'bi-grid';
    $cards = $section['cards'] ?? [];
    $sectionId = $key === 'platform_health' ? 'platform-health' : 'platform-section-'.$key;
@endphp

<x-settings-center.section
    :id="$sectionId"
    :icon="$icon"
    :title="$label"
    :description="$description"
    data-platform-section="{{ $key }}"
    data-platform-searchable="{{ strtolower($label.' '.($description ?? '')) }}"
    class="settings-center-platform__section"
>
    <div class="row g-3 settings-center-platform__cards">
        @foreach($cards as $card)
            <div
                @class([$card->columnClass(), 'settings-center-platform__card-slot'])
                data-platform-card-slot="{{ $card->key }}"
                data-platform-searchable="{{ strtolower($card->title.' '.($card->subtitle ?? '')) }}"
            >
                <x-platform.card :card="$card" />
            </div>
        @endforeach
    </div>
</x-settings-center.section>
