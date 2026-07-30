@props(['header', 'monthLabel'])

@php
    /** @var \App\Data\Workforce\WorkforceMember360Header $header */
@endphp

<header class="wm360-header">
    <div class="wm360-header__identity">
        <span class="wm360-header__avatar" aria-hidden="true">{{ $header->initials }}</span>
        <div class="wm360-header__text">
            <div class="wm360-header__name-row">
                <h2 class="wm360-header__name">{{ $header->name }}</h2>
                <span @class([
                    'wm360-status-badge',
                    'wm360-status-badge--active' => $header->isActive,
                    'wm360-status-badge--inactive' => ! $header->isActive,
                ])>
                    {{ $header->employmentStatusLabel }}
                </span>
            </div>
            @if($header->roleLabel)
                <div class="wm360-header__role">{{ $header->roleLabel }}</div>
            @endif
            <div class="wm360-header__meta">
                <span class="wm360-header__meta-item" title="Team is not configured yet">
                    Team · {{ $header->teamLabel ?? 'Not set' }}
                </span>
                <span class="wm360-header__meta-item" title="Joining date is not configured yet">
                    Joined · {{ $header->joiningDateLabel ?? 'Not set' }}
                </span>
                <span class="wm360-header__meta-item">{{ $monthLabel }}</span>
            </div>
        </div>
    </div>
</header>
