@props([
    'panel',
    'incident',
])

@php
    use App\Enums\SerialInsightStatus;

    $summaryPayload = $panel['summary_payload'] ?? [];
    $translateUrl = $panel['translate_url'] ?? null;
    $status = $panel['current_status'] ?? ['label' => 'Unknown', 'tone' => 'neutral'];
    $waiting = $panel['waiting'] ?? ['party' => 'Nobody', 'since_label' => null];
    $blockers = is_array($panel['blockers'] ?? null) ? $panel['blockers'] : [];
    $risks = is_array($panel['risks'] ?? null) ? $panel['risks'] : [];
    $action = $panel['recommended_action'] ?? [];
    $evidence = is_array($panel['evidence'] ?? null) ? $panel['evidence'] : [];
    $timelineEvents = is_array($panel['timeline_events'] ?? null) ? $panel['timeline_events'] : [];
    $summaryLines = is_array($panel['executive_summary_lines'] ?? null) ? $panel['executive_summary_lines'] : [];
    $serialInsight = $panel['serial_insight'] ?? null;
    $incidentId = $panel['incident_id'] ?? $incident->id;
@endphp

<section {{ $attributes->merge(['class' => 'c360-ira-panel']) }}
         data-customer-360-section="executive-summary"
         data-ira-executive-summary
         data-ira-panel
         @if($translateUrl) data-ira-translate-url="{{ $translateUrl }}" @endif
         aria-labelledby="c360-ira-panel-heading">

    <header class="c360-ira-panel-header">
        <div class="c360-ira-panel-heading-wrap">
            <h2 class="c360-ira-panel-heading" id="c360-ira-panel-heading">
                <i class="bi bi-stars" aria-hidden="true"></i>
                {{ $panel['heading'] ?? 'IRA' }}
            </h2>
            <p class="c360-ira-panel-subtitle">{{ $panel['subtitle'] ?? 'Case intelligence' }}</p>
        </div>
        <div class="c360-ira-panel-header-actions">
            @if($translateUrl)
                <button type="button"
                        class="c360-ira-lang-toggle"
                        data-ira-summary-lang-toggle
                        aria-pressed="false"
                        aria-label="Toggle Hindi translation">
                    हिन्दी
                </button>
            @endif
            <span class="c360-ira-panel-badge">Read only</span>
        </div>
    </header>

    <div class="c360-ira-panel-body"
         data-ira-summary-content
         data-ira-summary-en='@json($summaryPayload)'>

        {{-- 1. Executive Summary --}}
        <section class="c360-ira-panel-section" aria-labelledby="ira-section-summary">
            <h3 class="c360-ira-panel-section-title" id="ira-section-summary">Executive Summary</h3>
            <ul class="c360-ira-panel-summary-list" data-ira-summary-block="executive">
                @foreach($summaryLines as $line)
                    @continue(str_starts_with($line, 'Customer journey:'))
                    @continue(str_contains(strtolower($line), 'confidence:'))
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            @if(($panel['executive_paragraph'] ?? '') !== '' && $summaryLines === [])
                <p class="c360-ira-panel-summary-text">{{ $panel['executive_paragraph'] }}</p>
            @endif
        </section>

        {{-- 2. Current Status --}}
        <section class="c360-ira-panel-section" aria-labelledby="ira-section-status">
            <h3 class="c360-ira-panel-section-title" id="ira-section-status">Current Status</h3>
            <p class="c360-ira-panel-status c360-ira-panel-status--{{ $status['tone'] ?? 'neutral' }}">
                {{ $status['label'] ?? 'Unknown' }}
            </p>
        </section>

        {{-- 3. Waiting party --}}
        <section class="c360-ira-panel-section" aria-labelledby="ira-section-waiting">
            <h3 class="c360-ira-panel-section-title" id="ira-section-waiting">Who are we waiting for?</h3>
            <div class="c360-ira-panel-waiting">
                <span class="c360-ira-panel-waiting-party">{{ $waiting['party'] ?? 'Nobody' }}</span>
                @if(filled($waiting['since_label'] ?? null))
                    <span class="c360-ira-panel-waiting-meta">
                        Waiting since {{ $waiting['since_label'] }}
                    </span>
                @endif
                @if(filled($waiting['reason_label'] ?? null) && ($waiting['is_waiting'] ?? false))
                    <span class="c360-ira-panel-waiting-meta">
                        For {{ $waiting['reason_label'] }}
                    </span>
                @endif
            </div>
        </section>

        {{-- 4. Blockers --}}
        <section class="c360-ira-panel-section" aria-labelledby="ira-section-blockers">
            <h3 class="c360-ira-panel-section-title" id="ira-section-blockers">Current Blockers</h3>
            @if($blockers === [])
                <p class="c360-ira-panel-empty">No active blockers.</p>
            @else
                <ul class="c360-ira-panel-blockers">
                    @foreach($blockers as $blocker)
                        <li class="c360-ira-panel-blocker c360-ira-panel-blocker--{{ $blocker['severity'] ?? 'medium' }}">
                            <span class="c360-ira-panel-blocker-label">{{ $blocker['label'] }}</span>
                            <span class="c360-ira-panel-blocker-party">{{ $blocker['party'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 5. Risks --}}
        <section class="c360-ira-panel-section" aria-labelledby="ira-section-risks">
            <h3 class="c360-ira-panel-section-title" id="ira-section-risks">Risk Indicators</h3>
            @if($risks === [])
                <p class="c360-ira-panel-empty">No elevated risks detected.</p>
            @else
                <ul class="c360-ira-panel-risks">
                    @foreach($risks as $risk)
                        <li class="c360-ira-panel-risk c360-ira-panel-risk--{{ $risk['level'] }}">
                            <span class="c360-ira-panel-risk-level">{{ $risk['level_label'] }}</span>
                            <span class="c360-ira-panel-risk-copy">
                                <span class="c360-ira-panel-risk-title">{{ $risk['label'] }}</span>
                                <span class="c360-ira-panel-risk-explain">{{ $risk['explanation'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 6. Recommended Next Action --}}
        <section class="c360-ira-panel-section c360-ira-panel-section--action" aria-labelledby="ira-section-action">
            <h3 class="c360-ira-panel-section-title" id="ira-section-action">Recommended Next Action</h3>
            <p class="c360-ira-panel-action-text" data-ira-summary-block="recommendation">
                {{ $action['text'] ?? '' }}
            </p>

            @if($action['has_serial_action'] ?? false)
                <button type="button"
                        class="c360-ira-panel-primary-btn"
                        data-workspace-trigger="request-correct-serial"
                        data-workspace-incident-id="{{ $incidentId }}"
                        data-workspace-context="customer">
                    <i class="bi bi-send" aria-hidden="true"></i>
                    {{ $action['serial_action_label'] ?? 'Send request' }}
                </button>
            @elseif($action['serial_request_pending'] ?? false)
                <div class="c360-ira-panel-primary-btn c360-ira-panel-primary-btn--sent" role="status">
                    <i class="bi bi-check-circle" aria-hidden="true"></i>
                    <span>Serial Requested</span>
                </div>
            @else
                <div class="c360-ira-panel-primary-btn c360-ira-panel-primary-btn--display" role="status">
                    <i class="bi bi-lightning-charge" aria-hidden="true"></i>
                    <span>{{ $action['label'] ?? 'Next action' }}</span>
                </div>
            @endif

            @if(!empty($action['secondary_actions']))
                <ul class="c360-ira-panel-secondary" aria-label="Secondary actions">
                    @foreach($action['secondary_actions'] as $secondary)
                        <li>
                            <i class="bi {{ $secondary['icon'] ?? 'bi-circle' }}" aria-hidden="true"></i>
                            {{ $secondary['label'] ?? '' }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 7. Why IRA thinks this (structured evidence) --}}
        <section class="c360-ira-panel-section" aria-labelledby="ira-section-why" id="ira-why-evidence">
            <h3 class="c360-ira-panel-section-title" id="ira-section-why">Why IRA thinks this</h3>
            @if($evidence === [])
                <p class="c360-ira-panel-empty">No structured evidence available yet.</p>
            @else
                <ul class="c360-ira-panel-evidence" role="list">
                    @foreach($evidence as $item)
                        @php
                            $tone = $item['tone'] ?? 'positive';
                            $icon = match ($tone) {
                                'warning' => '⚠',
                                'negative' => '✖',
                                default => '✓',
                            };
                            $anchor = $item['anchor'] ?? null;
                        @endphp
                        <li @class(['c360-ira-panel-evidence-item', 'c360-ira-panel-evidence-item--'.$tone])
                            @if($anchor) id="{{ $anchor }}" @endif
                            role="listitem">
                            <span class="c360-ira-panel-evidence-icon" aria-hidden="true">{{ $icon }}</span>
                            <span class="c360-ira-panel-evidence-copy">
                                <a @if($anchor) href="#{{ $anchor }}" @endif class="c360-ira-panel-evidence-title">
                                    {{ $item['title'] }}
                                </a>
                                @if(filled($item['source'] ?? null))
                                    <span class="c360-ira-panel-evidence-source">{{ $item['source'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <details class="c360-ira-panel-details">
                <summary>IRA opinion</summary>
                <p class="c360-ira-panel-opinion" data-ira-summary-block="opinion">
                    {{ $panel['opinion'] ?? '' }}
                </p>
                @if($serialInsight?->isActionable())
                    <div class="c360-ira-panel-serial" data-ira-serial-insight>
                        <p class="c360-ira-panel-serial-status">
                            {{ $serialInsight->status->label() }}
                            · {{ $serialInsight->confidence->label() }} confidence
                        </p>
                        <p class="c360-ira-panel-opinion">{{ $serialInsight->explanation }}</p>
                        @if(filled($serialInsight->suggestedAction))
                            <p class="c360-ira-panel-opinion c360-ira-panel-opinion--muted">
                                {{ $serialInsight->suggestedAction }}
                            </p>
                        @endif
                    </div>
                @endif
            </details>
        </section>

        {{-- 8. Timeline (collapsed) --}}
        <details class="c360-ira-panel-details c360-ira-panel-timeline" data-c360-ira-collapse>
            <summary>
                <span>Timeline</span>
                @if(($panel['timeline_total'] ?? 0) > 0)
                    <span class="c360-ira-panel-timeline-count">{{ $panel['timeline_total'] }}</span>
                @endif
            </summary>
            @if($timelineEvents === [])
                <p class="c360-ira-panel-empty mb-0">No timeline events in this snapshot.</p>
            @else
                <ol class="c360-ira-panel-timeline-list">
                    @foreach($timelineEvents as $event)
                        <li>
                            <span class="c360-ira-panel-timeline-title">{{ $event['title'] }}</span>
                            <time class="c360-ira-panel-timeline-time">{{ $event['occurred_at_label'] }}</time>
                        </li>
                    @endforeach
                </ol>
                <button type="button"
                        class="c360-ira-panel-timeline-link"
                        data-customer-360-tab="timeline">
                    Open full timeline
                </button>
            @endif
        </details>
    </div>
</section>
