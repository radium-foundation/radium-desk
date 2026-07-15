@props([
    'title' => null,
    'headingId' => null,
    'variant' => 'default',
])

@php
    $sectionClass = match ($variant) {
        'flat' => 'c360-dialog-section c360-dialog-section--flat',
        default => 'c360-dialog-section',
    };
@endphp

<section {{ $attributes->merge(['class' => $sectionClass]) }}
         @if(filled($headingId)) aria-labelledby="{{ $headingId }}" @endif>
    @if(filled($title))
        <h3 class="c360-dialog-section-title"
            @if(filled($headingId)) id="{{ $headingId }}" @endif>
            {{ $title }}
        </h3>
    @endif
    {{ $slot }}
</section>
