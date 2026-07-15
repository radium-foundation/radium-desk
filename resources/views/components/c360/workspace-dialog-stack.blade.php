@props([])

<div {{ $attributes->merge(['class' => 'workspace-dialog-stack']) }}>
    {{ $slot }}
</div>
