@props([
    'signals',
])

@php
    $communication = is_array($signals['communication'] ?? null) ? $signals['communication'] : [];
    $status = is_array($signals['status'] ?? null) ? $signals['status'] : [];
    $sentiment = is_array($signals['sentiment'] ?? null) ? $signals['sentiment'] : [];
    $risk = is_array($signals['risk'] ?? null) ? $signals['risk'] : [];
    $waiting = is_array($signals['waiting'] ?? null) ? $signals['waiting'] : [];
    $agent = is_array($signals['assigned_agent'] ?? null) ? $signals['assigned_agent'] : [];
    $nba = is_array($signals['next_best_action'] ?? null) ? $signals['next_best_action'] : [];
    $confidence = is_array($signals['confidence'] ?? null) ? $signals['confidence'] : [];
@endphp

<div class="c360-ira-v2-signal-bar" data-ira-v2-signal-bar role="group" aria-label="IRA case signals">
    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--'.($status['tone'] ?? 'neutral')])>
        <span class="c360-ira-v2-signal-label">Status</span>
        <span class="c360-ira-v2-signal-value">{{ $status['label'] ?? '—' }}</span>
    </div>

    <div class="c360-ira-v2-signal c360-ira-v2-signal--comms" data-ira-v2-comm-counts>
        <span class="c360-ira-v2-signal-label">Comms</span>
        <span class="c360-ira-v2-signal-value c360-ira-v2-comm-grid">
            <span title="WhatsApp"><i class="bi bi-whatsapp" aria-hidden="true"></i>{{ (int) ($communication['whatsapp'] ?? 0) }}</span>
            <span title="Email"><i class="bi bi-envelope" aria-hidden="true"></i>{{ (int) ($communication['email'] ?? 0) }}</span>
            <span title="Phone"><i class="bi bi-telephone" aria-hidden="true"></i>{{ (int) ($communication['phone'] ?? 0) }}</span>
            <span title="Telegram"><i class="bi bi-telegram" aria-hidden="true"></i>{{ (int) ($communication['telegram'] ?? 0) }}</span>
        </span>
    </div>

    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--'.($sentiment['tone'] ?? 'muted')])>
        <span class="c360-ira-v2-signal-label">Sentiment</span>
        <span class="c360-ira-v2-signal-value">{{ $sentiment['label'] ?? 'Unknown' }}</span>
    </div>

    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--'.($risk['tone'] ?? 'muted')])>
        <span class="c360-ira-v2-signal-label">Risk</span>
        <span class="c360-ira-v2-signal-value">{{ $risk['label'] ?? 'None' }}</span>
    </div>

    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--'.($waiting['tone'] ?? 'muted')])>
        <span class="c360-ira-v2-signal-label">Waiting</span>
        <span class="c360-ira-v2-signal-value">{{ $waiting['label'] ?? '—' }}</span>
    </div>

    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--'.($agent['tone'] ?? 'muted')])>
        <span class="c360-ira-v2-signal-label">Assignee</span>
        <span class="c360-ira-v2-signal-value">{{ $agent['label'] ?? 'Unassigned' }}</span>
    </div>

    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--nba', 'c360-ira-v2-signal--'.($nba['tone'] ?? 'info')])>
        <span class="c360-ira-v2-signal-label">Next best action</span>
        <span class="c360-ira-v2-signal-value" title="{{ $nba['label'] ?? '' }}">{{ $nba['label'] ?? '—' }}</span>
    </div>

    <div @class(['c360-ira-v2-signal', 'c360-ira-v2-signal--'.($confidence['tone'] ?? 'muted')])>
        <span class="c360-ira-v2-signal-label">Confidence</span>
        <span class="c360-ira-v2-signal-value">
            {{ $confidence['label'] ?? '—' }}
            @if(isset($confidence['score']))
                <span class="c360-ira-v2-signal-score">{{ (int) $confidence['score'] }}</span>
            @endif
        </span>
    </div>
</div>
