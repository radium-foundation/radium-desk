@props([
    'active' => 'workforce',
])

@php
    $workspaceActive = match ($active) {
        'team' => 'workforce',
        'performance' => 'performance',
        'leave' => 'leave',
        default => $active,
    };
@endphp

@include('navigation.control-and-admin-workspace-nav', ['active' => $workspaceActive])
