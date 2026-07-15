@props([])

<div {{ $attributes->merge(['class' => 'workspace-kpi-secondary']) }}>
    {{ $slot }}
</div>
