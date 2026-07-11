@props([
    'title' => null,
    'headingId' => null,
])

<section {{ $attributes->merge(['class' => 'c360-dialog-section']) }}
         @if(filled($headingId)) aria-labelledby="{{ $headingId }}" @endif>
    @if(filled($title))
        <h3 class="c360-dialog-section-title"
            @if(filled($headingId)) id="{{ $headingId }}" @endif>
            {{ $title }}
        </h3>
    @endif
    {{ $slot }}
</section>
