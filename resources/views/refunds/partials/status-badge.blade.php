@props(['status'])

<span @class([
    'badge',
    'text-bg-warning' => $status === \App\Enums\RefundStatus::Pending,
    'text-bg-success' => $status === \App\Enums\RefundStatus::Approved,
    'text-bg-danger' => $status === \App\Enums\RefundStatus::Rejected,
])>
    {{ $status->label() }}
</span>
