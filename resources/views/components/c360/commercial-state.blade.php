@props([
    'commercialState' => null,
])

@php
    $state = is_array($commercialState) ? $commercialState : null;
    $show = (bool) ($state['show_banner'] ?? false);
@endphp

@if($show && $state !== null)
    <section class="c360-commercial-state c360-commercial-state--{{ $state['banner_variant'] ?? 'muted' }}"
             data-customer-360-section="commercial-state"
             data-commercial-state="{{ $state['state'] ?? '' }}"
             aria-label="Commercial state">
        <div class="c360-commercial-state-header">
            <span class="c360-commercial-state-kicker">Commercial State</span>
            <span class="c360-commercial-state-headline">{{ $state['headline'] ?? $state['label'] ?? '' }}</span>
        </div>
        @if(filled($state['summary'] ?? null))
            <p class="c360-commercial-state-summary">{{ $state['summary'] }}</p>
        @endif
        @if(! empty($state['details']))
            <dl class="c360-commercial-state-details">
                @foreach($state['details'] as $detail)
                    <div class="c360-commercial-state-detail">
                        <dt>{{ $detail['label'] ?? '' }}</dt>
                        <dd>{{ $detail['value'] ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
@endif
