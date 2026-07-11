@props([
    'lines' => 3,
    'variant' => 'card',
])

<div {{ $attributes->merge(['class' => 'c360-skeleton c360-skeleton--' . $variant]) }}
     aria-hidden="true">
    @if($variant === 'ira')
        <div class="c360-skeleton-line c360-skeleton-line--title"></div>
        <div class="c360-skeleton-line c360-skeleton-line--hero"></div>
        <div class="c360-skeleton-line c360-skeleton-line--bar"></div>
        @for($i = 0; $i < $lines; $i++)
            <div class="c360-skeleton-line"></div>
        @endfor
    @elseif($variant === 'chips')
        <div class="c360-skeleton-chips">
            @for($i = 0; $i < 6; $i++)
                <div class="c360-skeleton-chip"></div>
            @endfor
        </div>
    @else
        <div class="c360-skeleton-line c360-skeleton-line--title"></div>
        @for($i = 0; $i < $lines; $i++)
            <div class="c360-skeleton-line"></div>
        @endfor
    @endif
</div>
