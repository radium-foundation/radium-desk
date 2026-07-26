@props([
    'name',
    'status' => 'working',
    'isVirtual' => false,
])

@php
    $ringTone = match ($status) {
        'working', 'idle', 'login', 'assignment', 'status_changed', 'serial_updated', 'model_updated', 'remark' => 'working',
        'waiting_customer', 'on_ivr', 'email', 'whatsapp' => 'waiting',
        'auto_logout' => 'auto-logout',
        'ira' => 'ira',
        'break' => 'break',
        'leave' => 'leave',
        default => 'offline',
    };

    if ($isVirtual) {
        $initials = 'IRA';
    } else {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $initials = count($parts) >= 2
            ? strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[1], 0, 1))
            : strtoupper(mb_substr(trim((string) $name), 0, 2));
        $initials = $initials !== '' ? $initials : '?';
    }
@endphp

<span {{ $attributes->class([
    'team-activity-avatar',
    'team-activity-avatar--'.$ringTone,
    'team-activity-avatar--virtual' => $isVirtual,
]) }}
      aria-hidden="true">
    <span class="team-activity-avatar__inner">{{ $initials }}</span>
</span>
