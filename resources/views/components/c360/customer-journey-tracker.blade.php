@props([
    'milestones' => [],
    'currentIndex' => null,
])

@php
    use Illuminate\Support\Str;

    $steps = is_array($milestones) ? array_values($milestones) : [];
    $total = count($steps);
    $activeIndex = is_int($currentIndex) ? $currentIndex : ($total > 0 ? $total - 1 : 0);
@endphp

@if($steps !== [])
    <div {{ $attributes->merge(['class' => 'c360-journey-tracker']) }}
         role="list"
         aria-label="Customer journey progress">
        <div class="c360-journey-track" aria-hidden="true">
            @foreach($steps as $index => $step)
                @php
                    $state = $index < $activeIndex ? 'completed' : ($index === $activeIndex ? 'current' : 'pending');
                @endphp
                <span @class(['c360-journey-node', 'c360-journey-node--' . $state])></span>
                @if($index < $total - 1)
                    <span @class(['c360-journey-segment', 'c360-journey-segment--' . ($index < $activeIndex ? 'completed' : 'pending')])></span>
                @endif
            @endforeach
        </div>
        <div class="c360-journey-labels">
            @foreach($steps as $index => $step)
                @php
                    $state = $index < $activeIndex ? 'completed' : ($index === $activeIndex ? 'current' : 'pending');
                    $fullLabel = is_array($step) ? ($step['label'] ?? '') : (string) $step;
                    $shortLabel = Str::length($fullLabel) > 14
                        ? Str::limit($fullLabel, 12, '…')
                        : $fullLabel;
                @endphp
                <span @class([
                    'c360-journey-label',
                    'c360-journey-label--' . $state,
                    'c360-journey-label--alt' => $index % 2 === 1,
                ])
                      role="listitem"
                      title="{{ $fullLabel }}">
                    {{ $shortLabel }}
                </span>
            @endforeach
        </div>
    </div>
@endif
