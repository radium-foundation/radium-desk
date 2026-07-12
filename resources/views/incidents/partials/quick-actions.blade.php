@props([
    'incident',
    'sticky' => false,
])

@php
    use App\Enums\IncidentStatus;

    $canRemark = auth()->user()->can('create', App\Models\Remark::class);
    $canAssign = auth()->user()->can('reassign', $incident);
    $canUpdate = auth()->user()->can('update', $incident);
    $isClosed = $incident->status === IncidentStatus::Closed;
    $canOpenMoreMenu = ($canAssign && ! $isClosed) || ($canUpdate && ! $isClosed) || ($canUpdate && $isClosed);
    $dashboardMoreUrl = route('dashboard', [
        'open_customer_360' => $incident->id,
        'open_more_menu' => 1,
        'open_customer_360_reference' => $incident->display_reference,
    ]);
@endphp

<div @class([
    'service-case-quick-actions',
    'service-case-quick-actions--sticky' => $sticky,
    'd-none d-lg-flex' => $sticky,
]) @if($sticky) id="service-case-sticky-actions" data-sticky-actions @endif @if(! $sticky) id="service-case-quick-actions" data-quick-actions @endif>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        @if($canRemark)
            <button type="button"
                    class="btn btn-outline-primary btn-sm"
                    data-workspace-trigger="remark"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="service_case"
                    aria-label="Add note for {{ $incident->display_reference }}">
                <span aria-hidden="true">📝</span> Note
            </button>
        @endif
        @if($canOpenMoreMenu)
            <a href="{{ $dashboardMoreUrl }}"
               class="btn btn-outline-primary btn-sm"
               aria-label="More actions for {{ $incident->display_reference }}">
                <span aria-hidden="true">⋯</span> More
            </a>
        @endif
    </div>
    @if(! $sticky)
        @include('service-cases.partials.action-capabilities', [
            'incident' => $incident,
            'renderShortcutsHint' => true,
        ])
    @endif
</div>
