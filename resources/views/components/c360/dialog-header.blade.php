@props([
    'icon' => null,
    'title',
    'subtitle' => null,
    'showClose' => true,
])

<div {{ $attributes->merge(['class' => 'modal-header c360-dialog-header border-0 pb-0']) }}>
    <div class="c360-dialog-header-content">
        @if(filled($icon))
            <span class="c360-dialog-header-icon" aria-hidden="true">{{ $icon }}</span>
        @endif
        <div class="c360-dialog-header-text">
            <h2 class="modal-title c360-dialog-header-title mb-0">{{ $title }}</h2>
            @if(filled($subtitle))
                <p class="c360-dialog-header-subtitle mb-0">{{ $subtitle }}</p>
            @endif
        </div>
    </div>
    @if($showClose)
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    @endif
</div>
