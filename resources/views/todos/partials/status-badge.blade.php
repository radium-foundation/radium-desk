@props(['status'])

<span @class([
    'badge',
    'text-bg-primary' => $status === \App\Enums\TodoStatus::Open,
    'text-bg-success' => $status === \App\Enums\TodoStatus::Completed,
    'text-bg-secondary' => $status === \App\Enums\TodoStatus::Cancelled,
])>
    {{ $status->label() }}
</span>
