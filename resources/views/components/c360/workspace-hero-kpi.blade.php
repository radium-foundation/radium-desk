@props([
    'amount',
    'caption' => null,
])

<div {{ $attributes->merge(['class' => 'workspace-hero-kpi']) }}>
    <div class="workspace-hero-kpi__amount">{{ $amount }}</div>
    @if(filled($caption))
        <div class="workspace-hero-kpi__caption">{{ $caption }}</div>
    @endif
    @if(isset($secondary))
        <div class="workspace-hero-kpi__secondary">
            {{ $secondary }}
        </div>
    @endif
    @if(isset($meta))
        <div class="workspace-hero-kpi__meta">
            {{ $meta }}
        </div>
    @endif
</div>
