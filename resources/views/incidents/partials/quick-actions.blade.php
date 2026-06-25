@props([
    'incident',
    'sticky' => false,
])

<div @class([
    'service-case-quick-actions',
    'service-case-quick-actions--sticky' => $sticky,
    'd-none d-lg-flex' => $sticky,
]) @if($sticky) id="service-case-sticky-actions" data-sticky-actions @endif @if(! $sticky) id="service-case-quick-actions" data-quick-actions @endif>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        @include('service-cases.partials.action-buttons', ['incident' => $incident])
    </div>
    @if(! $sticky)
        @include('service-cases.partials.action-capabilities', [
            'incident' => $incident,
            'renderShortcutsHint' => true,
        ])
    @endif
</div>
