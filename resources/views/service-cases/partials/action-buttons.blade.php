@php
    use App\Enums\IncidentStatus;

    $canAssign = auth()->user()->can('reassign', $incident);
    $canUpdate = auth()->user()->can('update', $incident);
    $canRemark = auth()->user()->can('create', App\Models\Remark::class);
    $isClosed = $incident->status === IncidentStatus::Closed;
    $canAction = ($canAssign && ! $isClosed) || ($canUpdate && ! $isClosed) || ($canUpdate && $isClosed);
@endphp

@if($canAction)
    <button type="button"
            class="btn btn-primary btn-sm"
            data-workspace-trigger="action"
            data-workspace-incident-id="{{ $incident->id }}"
            data-workspace-context="service_case">
        <i class="bi bi-lightning-charge me-1"></i> Action
    </button>
@endif
@if($canRemark)
    <button type="button"
            class="btn btn-outline-primary btn-sm"
            data-workspace-trigger="remark"
            data-workspace-incident-id="{{ $incident->id }}"
            data-workspace-context="service_case">
        <i class="bi bi-chat-left-text me-1"></i> Add Remark
    </button>
@endif
