@props([
    'cards' => [],
    'variant' => 'default',
])

@php
    $isExecutive = $variant === 'executive';
    $isHealth = $variant === 'health';
@endphp

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
            data-platform-searchable="{{ strtolower($card->title) }}"
        >
            <x-platform.card :card="$card" />
        </div>
    @endforeach
</div>
