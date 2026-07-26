@props([
    'entry',
])

@php
    /** @var \App\Data\TeamActivityEntry $entry */
    $label = $entry->label;
    $icon = match (true) {
        str_contains($label, 'WhatsApp') => 'bi-whatsapp',
        str_contains($label, 'Email') => 'bi-envelope',
        str_contains($label, 'IVR') || str_contains($label, 'Call') => 'bi-telephone',
        str_contains($label, 'Assigned') || str_contains($label, 'Reassigned') || str_contains($label, 'Escalated') => 'bi-person-check',
        str_starts_with($label, 'IRA') => 'bi-robot',
        str_contains($label, 'Approval') => 'bi-patch-check',
        str_contains($label, 'Refund') => 'bi-currency-rupee',
        str_contains($label, 'Remark') => 'bi-chat-left-text',
        str_contains($label, 'Driver Guide') => 'bi-file-earmark-text',
        str_contains($label, 'Availability') => 'bi-circle-half',
        str_contains($label, 'Leave') => 'bi-calendar-x',
        str_contains($label, 'Waiting') => 'bi-hourglass-split',
        str_contains($label, 'Status Changed') => 'bi-arrow-left-right',
        default => 'bi-activity',
    };

    $references = collect([$entry->serviceCaseReference, $entry->orderReference])
        ->filter(static fn (?string $value): bool => filled($value))
        ->implode(' • ');

    if ($references === '' && filled($entry->reference)) {
        $references = (string) $entry->reference;
    }
@endphp

<div {{ $attributes->class(['team-activity-latest-event']) }}>
    <span class="team-activity-latest-event__title">
        <i class="bi {{ $icon }} team-activity-latest-event__icon" aria-hidden="true"></i>
        <span class="team-activity-latest-event__label">{{ $label }}</span>
    </span>

    @if($references !== '')
        <span class="team-activity-latest-event__refs">{{ $references }}</span>
    @endif

    <time class="team-activity-latest-event__time"
          datetime="{{ $entry->at->toIso8601String() }}">{{ display_app_timeline_relative($entry->at) }}</time>
</div>
