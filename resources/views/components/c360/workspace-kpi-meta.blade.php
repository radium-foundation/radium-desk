@props([])

<div {{ $attributes->merge(['class' => 'workspace-kpi-meta']) }}>
    {{ $slot }}
</div>
