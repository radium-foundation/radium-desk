@props([
    'panel',
    'incident',
])

@php
    $summaryPayload = $panel['summary_payload'] ?? [];
    $translateUrl = $panel['translate_url'] ?? null;
    $brief = is_array($panel['executive_brief'] ?? null) ? $panel['executive_brief'] : [];
    $narrativeHtml = (string) ($panel['executive_narrative_html'] ?? $panel['executive_paragraph'] ?? '');
    $action = $panel['recommended_action'] ?? [];
    $communicationItems = is_array($panel['communication_items'] ?? null) ? $panel['communication_items'] : [];
    $contributors = is_array($panel['case_contributors'] ?? null) ? $panel['case_contributors'] : [];
    $blockers = is_array($panel['blockers'] ?? null) ? $panel['blockers'] : [];
    $risks = is_array($panel['risks'] ?? null) ? $panel['risks'] : [];
    $evidence = is_array($panel['evidence'] ?? null) ? $panel['evidence'] : [];
    $timelineEvents = is_array($panel['timeline_events'] ?? null) ? $panel['timeline_events'] : [];
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
            <p class="c360-ira-panel-subtitle">{{ $panel['subtitle'] ?? 'Operations briefing' }}</p>
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

        {{-- 1. Executive Brief --}}
        @if($brief !== [])
            <section class="c360-ira-panel-section c360-ira-brief" aria-labelledby="ira-section-brief">
                <h3 class="c360-ira-panel-section-title" id="ira-section-brief">Executive Brief</h3>
                <dl class="c360-ira-brief-grid">
                    @foreach($brief as $row)
                        <div @class([
                            'c360-ira-brief-item',
                            'c360-ira-brief-item--'.($row['tone'] ?? 'neutral') => filled($row['tone'] ?? null),
                        ])>
                            <dt>{{ $row['label'] }}</dt>
                            <dd>
                                @if(($row['label'] ?? '') === 'Current Owner')
                                    <strong class="c360-ira-person">{{ $row['value'] }}</strong>
                                @else
                                    {{ $row['value'] }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        {{-- 2. Executive Narrative --}}
        @if($narrativeHtml !== '')
            <section class="c360-ira-panel-section c360-ira-narrative" aria-labelledby="ira-section-narrative">
                <h3 class="c360-ira-panel-section-title" id="ira-section-narrative">Executive Narrative</h3>
                <p class="c360-ira-narrative-text"
                   data-ira-summary-block="executive"
                   data-ira-summary-mode="narrative">
                    {!! $narrativeHtml !!}
                </p>
            </section>
        @endif

        {{-- 3. Next Action --}}
        <section class="c360-ira-panel-section c360-ira-panel-section--action c360-ira-next-action"
                 aria-labelledby="ira-section-action">
            <h3 class="c360-ira-panel-section-title" id="ira-section-action">Next Action</h3>
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

        {{-- 4. Communication --}}
        <section class="c360-ira-panel-section c360-ira-comm-section" aria-labelledby="ira-section-communication">
            <h3 class="c360-ira-panel-section-title" id="ira-section-communication">Communication</h3>
            @if($communicationItems === [])
                <p class="c360-ira-panel-empty">No customer communication recorded yet.</p>
            @else
                <ul class="c360-ira-comm-feed" data-ira-summary-block="communication">
                    @foreach($communicationItems as $item)
                        <li @class(['c360-ira-comm-item', 'c360-ira-comm-item--'.($item['kind'] ?? 'outbound')])>
                            <div class="c360-ira-comm-item-head">
                                <span class="c360-ira-comm-actor">{!! $item['actor_html'] !!}</span>
                                @if(filled($item['channel'] ?? null))
                                    <span class="c360-ira-comm-channel">{{ $item['channel'] }}</span>
                                @endif
                            </div>
                            <p class="c360-ira-comm-detail">{!! $item['detail_html'] !!}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 5. Case Contributors --}}
        @if(($panel['has_contributors'] ?? false) && $contributors !== [])
            <section class="c360-ira-panel-section c360-ira-contributors" aria-labelledby="ira-section-contributors">
                <h3 class="c360-ira-panel-section-title" id="ira-section-contributors">Case Contributors</h3>
                <ul class="c360-ira-contributor-list">
                    @foreach($contributors as $contributor)
                        <li class="c360-ira-contributor c360-ira-contributor--{{ $contributor['kind'] ?? 'agent' }}">
                            <i class="bi {{ $contributor['icon'] ?? 'bi-person' }}" aria-hidden="true"></i>
                            <span class="c360-ira-contributor-copy">
                                <span class="c360-ira-contributor-role">{{ $contributor['role'] }}</span>
                                <span class="c360-ira-contributor-name">{!! $contributor['name_html'] !!}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Secondary: blockers & risks (only when present; avoids empty noise) --}}
        @if(($panel['has_blockers'] ?? false) || ($panel['has_risks'] ?? false))
            <details class="c360-ira-panel-details" data-c360-ira-collapse>
                <summary>Blockers &amp; risks</summary>
                @if($blockers !== [])
                    <ul class="c360-ira-panel-blockers">
                        @foreach($blockers as $blocker)
                            <li class="c360-ira-panel-blocker c360-ira-panel-blocker--{{ $blocker['severity'] ?? 'medium' }}">
                                <span class="c360-ira-panel-blocker-label">{{ $blocker['label'] }}</span>
                                <span class="c360-ira-panel-blocker-party">{{ $blocker['party'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($risks !== [])
                    <ul class="c360-ira-panel-risks">
                        @foreach($risks as $risk)
                            <li class="c360-ira-panel-risk c360-ira-panel-risk--{{ $risk['level'] }}">
                                <span class="c360-ira-panel-risk-level">{{ $risk['level_label'] }}</span>
                                <span class="c360-ira-panel-risk-copy">
                                    <span class="c360-ira-panel-risk-title">{{ $risk['label'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
        @endif

        {{-- Secondary: evidence / opinion --}}
        <details class="c360-ira-panel-details" aria-labelledby="ira-section-why">
            <summary id="ira-section-why">Why IRA thinks this</summary>
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

        {{-- Timeline preview (collapsed) --}}
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
