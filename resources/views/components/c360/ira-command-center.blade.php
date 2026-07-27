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
    $actionCenter = is_array($panel['action_center'] ?? null) ? $panel['action_center'] : null;
    $journeyItems = is_array($panel['customer_journey_items'] ?? null) ? $panel['customer_journey_items'] : [];
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

        {{-- 3. Customer Journey --}}
        <section class="c360-ira-panel-section c360-ira-journey-section" aria-labelledby="ira-section-journey">
            <h3 class="c360-ira-panel-section-title" id="ira-section-journey">Customer Journey</h3>
            @if($journeyItems === [])
                <p class="c360-ira-panel-empty">No customer communication recorded yet.</p>
            @else
                <ol class="c360-ira-journey-feed" data-ira-summary-block="communication">
                    @foreach($journeyItems as $item)
                        <li @class([
                            'c360-ira-journey-item',
                            'c360-ira-journey-item--'.($item['kind'] ?? 'event'),
                        ])>
                            @if(filled($item['at_label'] ?? null))
                                <time class="c360-ira-journey-time">{{ $item['at_label'] }}</time>
                                <span class="c360-ira-journey-sep" aria-hidden="true">—</span>
                            @endif
                            <span class="c360-ira-journey-text">{{ $item['text'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- 4. Action Center (merged Advisor + Workspace) --}}
        @if($actionCenter !== null)
            <x-c360.action-center
                :actionCenter="$actionCenter"
                :incident="$incident"
                class="c360-ira-panel-section c360-ira-panel-section--action"
            />
        @else
            <section class="c360-ira-panel-section c360-ira-panel-section--action c360-ira-next-action"
                     aria-labelledby="ira-section-action">
                <h3 class="c360-ira-panel-section-title" id="ira-section-action">Next Action</h3>
                <p class="c360-ira-panel-action-text" data-ira-summary-block="recommendation">
                    {{ $action['text'] ?? '' }}
                </p>
            </section>
        @endif

        {{-- 5. Case Contributors (collapsed) --}}
        @if(($panel['has_contributors'] ?? false) && $contributors !== [])
            <details class="c360-ira-panel-details" data-c360-ira-collapse>
                <summary>Case Contributors</summary>
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
            </details>
        @endif

        {{-- Secondary: blockers & risks --}}
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
