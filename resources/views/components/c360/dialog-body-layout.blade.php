@props([
    'sidebar' => null,
])

<div {{ $attributes->merge(['class' => 'c360-dialog-layout']) }}>
    @if($sidebar !== null)
        <aside class="c360-dialog-sidebar" aria-label="Order metadata">
            {{ $sidebar }}
        </aside>
    @endif
    <div class="c360-dialog-main">
        {{ $slot }}
    </div>
</div>
