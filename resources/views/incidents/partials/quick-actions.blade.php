@props([
    'incident',
    'sticky' => false,
])

@php
    use App\Enums\IncidentStatus;

    $canAssign = auth()->user()->can('reassign', $incident);
    $canUpdate = auth()->user()->can('update', $incident);
    $canRemark = auth()->user()->can('create', App\Models\Remark::class);
    $canResolve = $canUpdate && ! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true);
    $canClose = $canUpdate && $incident->status !== IncidentStatus::Closed;
@endphp

<div @class([
    'service-case-quick-actions',
    'service-case-quick-actions--sticky' => $sticky,
    'd-none d-lg-flex' => $sticky,
]) @if($sticky) id="service-case-sticky-actions" data-sticky-actions @endif @if(! $sticky) id="service-case-quick-actions" data-quick-actions @endif>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        @if($canAssign)
            <button type="button"
                    class="btn btn-outline-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#assignModal"
                    data-sc-action="assign">
                <i class="bi bi-person-check me-1"></i> Assign
            </button>
        @endif
        @if($canRemark)
            <button type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#remarkModal"
                    data-sc-action="remark">
                <i class="bi bi-chat-left-text me-1"></i> Add Remark
            </button>
        @endif
        @if($canResolve)
            <button type="button"
                    class="btn btn-outline-success btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#resolveModal"
                    data-sc-action="resolve">
                <i class="bi bi-check-circle me-1"></i> Resolve
            </button>
        @endif
        @if($canClose)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#closeModal"
                    data-sc-action="close">
                <i class="bi bi-x-circle me-1"></i> Close
            </button>
        @endif
    </div>
    @if(! $sticky && ($canAssign || $canRemark || $canUpdate))
        <p class="text-muted small mb-0 mt-2">{{ config('ui.service_case.shortcuts_hint') }}</p>
    @endif
</div>
