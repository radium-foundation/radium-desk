@props(['status'])

<span @class([
    'badge',
    'text-bg-warning' => $status === \App\Enums\LeaveRequestStatus::Pending,
    'text-bg-success' => $status === \App\Enums\LeaveRequestStatus::Approved,
    'text-bg-danger' => $status === \App\Enums\LeaveRequestStatus::Rejected,
])>
    {{ $status->label() }}
</span>
