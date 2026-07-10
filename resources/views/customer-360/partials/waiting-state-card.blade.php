@php
    use App\Support\AppDateFormatter;
@endphp

@if($waitingStateCard)
    <section class="customer-360-waiting-card"
             data-customer-360-section="waiting-state"
             aria-labelledby="customer-360-waiting-heading">
        <div class="customer-360-waiting-card-header">
            <span class="customer-360-waiting-card-indicator" aria-hidden="true">🟡</span>
            <h2 class="customer-360-waiting-card-title" id="customer-360-waiting-heading">
                Waiting for customer response
            </h2>
        </div>

        <dl class="customer-360-waiting-grid">
            <div class="customer-360-waiting-item">
                <dt>Reason</dt>
                <dd>{{ $waitingStateCard['reason_label'] }}</dd>
            </div>
            @if(filled($waitingStateCard['waiting_duration_label'] ?? null))
                <div class="customer-360-waiting-item">
                    <dt>Waiting</dt>
                    <dd>{{ $waitingStateCard['waiting_duration_label'] }}</dd>
                </div>
            @endif
            @if(filled($waitingStateCard['requested_at_label'] ?? null))
                <div class="customer-360-waiting-item">
                    <dt>Requested</dt>
                    <dd>
                        <time datetime="{{ $waitingStateCard['started_at']->toIso8601String() }}"
                              title="{{ AppDateFormatter::datetime($waitingStateCard['started_at']) }}">
                            {{ $waitingStateCard['requested_at_label'] }}
                        </time>
                    </dd>
                </div>
            @endif
            <div class="customer-360-waiting-item">
                <dt>SLA</dt>
                <dd>{{ ($waitingStateCard['sla_paused'] ?? false) ? 'Paused' : 'Active' }}</dd>
            </div>
            @if(filled($waitingStateCard['reminder_policy_label'] ?? null))
                <div class="customer-360-waiting-item">
                    <dt>Reminder Policy</dt>
                    <dd>{{ $waitingStateCard['reminder_policy_label'] }}</dd>
                </div>
            @endif
            @if(filled($waitingStateCard['next_action_at'] ?? null))
                <div class="customer-360-waiting-item">
                    <dt>Next Action</dt>
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
