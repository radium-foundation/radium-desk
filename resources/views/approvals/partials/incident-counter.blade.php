@props(['count'])

@php
    $max = \App\Models\ApprovalNumber::MAX_INCIDENTS;
    $badgeClass = match (true) {
        $count >= $max => 'text-bg-danger',
        $count >= ($max - 5) => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
@endphp

<span class="badge {{ $badgeClass }}">{{ $count }} / {{ $max }}</span>
