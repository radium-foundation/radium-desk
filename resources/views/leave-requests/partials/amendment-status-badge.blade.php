@props(['amendment'])

<span @class([
    'badge',
    'text-bg-warning' => $amendment->status === \App\Enums\LeaveAmendmentStatus::Pending,
    'text-bg-success' => $amendment->status === \App\Enums\LeaveAmendmentStatus::Approved,
    'text-bg-danger' => $amendment->status === \App\Enums\LeaveAmendmentStatus::Rejected,
])>
    @if($amendment->status === \App\Enums\LeaveAmendmentStatus::Pending)
        {{ $amendment->type === \App\Enums\LeaveAmendmentType::Cancellation ? 'Cancellation Pending' : 'Amendment Pending' }}
    @else
        {{ $amendment->type->label() }} — {{ $amendment->status->label() }}
    @endif
</span>
