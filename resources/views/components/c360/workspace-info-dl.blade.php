@props([])

<dl {{ $attributes->merge(['class' => 'workspace-info-dl']) }}>
    {{ $slot }}
</dl>
