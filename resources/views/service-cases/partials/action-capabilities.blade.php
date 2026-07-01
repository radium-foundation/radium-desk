@php
    use App\Enums\IncidentStatus;

    $canAssign = auth()->user()->can('reassign', $incident);
    $canUpdate = auth()->user()->can('update', $incident);
    $canRemark = auth()->user()->can('create', App\Models\Remark::class);
    $isClosed = $incident->status === IncidentStatus::Closed;
    $canAction = ($canAssign && ! $isClosed) || ($canUpdate && ! $isClosed) || ($canUpdate && $isClosed);
@endphp

@if($renderShortcutsHint ?? false)
    @if($canAction || $canRemark)
        <p class="text-muted small mb-0 mt-2">{{ config('ui.service_case.shortcuts_hint') }}</p>
    @endif
@endif
