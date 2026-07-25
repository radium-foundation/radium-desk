@props([
    'id' => null,
    'icon' => 'bi-sliders',
    'title',
    'description' => null,
    'searchable' => true,
])

<section
    @if($id) id="{{ $id }}" @endif
    {{ $attributes->merge(['class' => 'system-settings-section']) }}
    aria-labelledby="{{ $id ? $id . '-title' : null }}"
    @if($searchable) data-system-settings-section @endif
>
    <div class="system-settings-section__card">
        <header class="system-settings-section__header">
            <div class="system-settings-section__icon" aria-hidden="true">
                <i class="bi {{ $icon }}"></i>
            </div>
            <div class="system-settings-section__heading">
                <h2 class="system-settings-section__title" @if($id) id="{{ $id }}-title" @endif>{{ $title }}</h2>
                @if(filled($description))
                    <p class="system-settings-section__description">{{ $description }}</p>
                @endif
            </div>
        </header>

        <div class="system-settings-section__body">
            {{ $slot }}
        </div>
    </div>
</section>
