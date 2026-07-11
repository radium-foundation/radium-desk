@php
    use App\Support\AppDateFormatter;
@endphp

@if($waitingStateCard)
    <section class="c360-waiting-banner"
             data-customer-360-section="waiting-state"
             aria-labelledby="customer-360-waiting-heading">
        <x-c360.status-banner variant="waiting" icon="ⓘ" id="customer-360-waiting-heading">
            Waiting for customer response
        </x-c360.status-banner>

        <dl class="c360-waiting-banner-grid">
            <div>
                <dt>Reason</dt>
                <dd>{{ $waitingStateCard['reason_label'] }}</dd>
            </div>
            @if(filled($waitingStateCard['waiting_duration_label'] ?? null))
                <div>
                    <dt>Waiting</dt>
                    <dd>{{ $waitingStateCard['waiting_duration_label'] }}</dd>
                </div>
            @endif
            <div>
                <dt>SLA</dt>
                <dd>{{ ($waitingStateCard['sla_paused'] ?? false) ? 'Paused' : 'Active' }}</dd>
            </div>
            @if(filled($waitingStateCard['next_action_at'] ?? null))
                <div>
                    <dt>Next action</dt>
                    <dd>
                        <time datetime="{{ $waitingStateCard['next_action_at']->toIso8601String() }}"
                              title="{{ AppDateFormatter::datetime($waitingStateCard['next_action_at']) }}">
                            {{ AppDateFormatter::datetime($waitingStateCard['next_action_at']) }}
                        </time>
                    </dd>
                </div>
            @endif
        </dl>
    </section>
@endif
