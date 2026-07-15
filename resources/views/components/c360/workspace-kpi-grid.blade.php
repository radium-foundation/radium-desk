@props([])

<div {{ $attributes->merge(['class' => 'workspace-kpi-grid']) }} role="group">
    {{ $slot }}
</div>
