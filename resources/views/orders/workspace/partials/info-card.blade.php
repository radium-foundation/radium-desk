<div @class([
    'order-workspace-card',
    'order-workspace-card--compact' => $compact ?? false,
])>
    <div class="order-workspace-card-header">
        @if(! empty($icon))
            <i class="bi {{ $icon }} order-workspace-card-icon" aria-hidden="true"></i>
        @endif
        <h3 class="order-workspace-card-title">{{ $title }}</h3>
    </div>
    <div class="order-workspace-card-body">
        {{ $slot }}
    </div>
</div>
