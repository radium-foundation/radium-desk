@props([
    'section',
])

@php
    $key = $section['key'] ?? '';
    $label = $section['label'] ?? '';
    $cards = $section['cards'] ?? [];
@endphp

<section class="mb-4" data-platform-section="{{ $key }}" aria-labelledby="platform-section-{{ $key }}">
    <h2 id="platform-section-{{ $key }}" class="h5 mb-3">{{ $label }}</h2>

    <div class="row g-3">
        @foreach($cards as $card)
            <div
                @class([$card->columnClass()])
                data-platform-card-slot="{{ $card->key }}"
            >
                <x-platform.card :card="$card" />
            </div>
        @endforeach
    </div>
</section>
