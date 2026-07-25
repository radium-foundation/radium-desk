@props([
    'id' => null,
    'title' => null,
    'description' => null,
    'flush' => false,
])

<div
    @if($id) id="{{ $id }}" @endif
    {{ $attributes->merge(['class' => 'system-settings-card' . ($flush ? ' system-settings-card--flush' : '')]) }}
>
    @if(filled($title))
        <div class="system-settings-card__header">
            <h3 class="system-settings-card__title">{{ $title }}</h3>
            @if(filled($description))
                <p class="system-settings-card__description">{{ $description }}</p>
            @endif
        </div>
    @endif

    <div class="system-settings-card__body">
        {{ $slot }}
    </div>
</div>
