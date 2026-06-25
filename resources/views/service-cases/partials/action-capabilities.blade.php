@php
    use App\Enums\IncidentStatus;

    $canAssign = auth()->user()->can('reassign', $incident);
    $canUpdate = auth()->user()->can('update', $incident);
    $canRemark = auth()->user()->can('create', App\Models\Remark::class);
    $canResolve = $canUpdate && ! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true);
    $canClose = $canUpdate && $incident->status !== IncidentStatus::Closed;
@endphp

@if($renderShortcutsHint ?? false)
    @if($canAssign || $canRemark || $canUpdate)
        <p class="text-muted small mb-0 mt-2">{{ config('ui.service_case.shortcuts_hint') }}</p>
    @endif
@endif
