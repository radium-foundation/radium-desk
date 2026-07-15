@props([])

<div {{ $attributes->merge(['class' => 'workspace-context-strip']) }} role="group" aria-label="Context">
    {{ $slot }}
</div>
