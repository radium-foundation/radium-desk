@props([
    'value',
])

@php
    /** @var string|null $value */
    $presenter = app(\App\Support\Dashboard\TeamActivityDurationPresenter::class);
    $value = is_string($value) ? trim($value) : '';
    $parts = $value !== '' ? $presenter->parts($value) : [];
@endphp

@if($value === '' || $value === '—')
    <span {{ $attributes }}>—</span>
@elseif($parts !== [])
    <span {{ $attributes->class(['team-activity-duration']) }}>
        @foreach($parts as $part)
            @if(! $loop->first)<span class="team-activity-duration__gap" aria-hidden="true"> </span>@endif
            <span class="team-activity-duration__segment">
                <span class="team-activity-duration__value">{{ $part['value'] }}</span><span class="team-activity-duration__unit">{{ $part['unit'] }}</span>
            </span>
        @endforeach
    </span>
@else
    <span {{ $attributes }}>{{ $value }}</span>
@endif
