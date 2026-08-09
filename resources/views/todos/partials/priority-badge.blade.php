@props(['priority'])

<span @class([
    'badge',
    'text-bg-secondary' => $priority === \App\Enums\TodoPriority::Low,
    'text-bg-light text-dark border' => $priority === \App\Enums\TodoPriority::Normal,
    'text-bg-danger' => $priority === \App\Enums\TodoPriority::High,
])>
    {{ $priority->label() }}
</span>
