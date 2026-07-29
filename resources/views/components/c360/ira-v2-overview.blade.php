@props([
    'overview',
    'incident',
])

@php
    $signals = is_array($overview['signal_bar'] ?? null) ? $overview['signal_bar'] : [];
    $storySections = is_array($overview['story_sections'] ?? null) ? $overview['story_sections'] : [];
    $narrativeHtml = (string) ($overview['executive_narrative_html'] ?? '');
    $actionCenter = is_array($overview['action_center'] ?? null) ? $overview['action_center'] : null;
    $action = is_array($overview['recommended_action'] ?? null) ? $overview['recommended_action'] : [];
    $openQuestions = is_array($overview['open_questions'] ?? null) ? $overview['open_questions'] : [];
    $blockers = is_array($overview['blockers'] ?? null) ? $overview['blockers'] : [];
    $risks = is_array($overview['risks'] ?? null) ? $overview['risks'] : [];
    $evidence = is_array($overview['evidence'] ?? null) ? $overview['evidence'] : [];
    $summaryPayload = $overview['summary_payload'] ?? [];
    $translateUrl = $overview['translate_url'] ?? null;
    $communicationBriefing = trim((string) ($overview['communication_briefing'] ?? ''));
@endphp

<section {{ $attributes->merge(['class' => 'c360-ira-panel c360-ira-v2-overview']) }}
         data-customer-360-section="executive-summary"
         data-ira-executive-summary
         data-ira-panel
         data-ira-v2-overview
         data-ira-v2-schema="{{ $overview['schema_version'] ?? '' }}"
         @if($translateUrl) data-ira-translate-url="{{ $translateUrl }}" @endif
         aria-labelledby="c360-ira-v2-heading">

    <header class="c360-ira-panel-header">
        <div class="c360-ira-panel-heading-wrap">
            <h2 class="c360-ira-panel-heading" id="c360-ira-v2-heading">
                <i class="bi bi-stars" aria-hidden="true"></i>
                {{ $overview['heading'] ?? 'IRA' }}
            </h2>
            <p class="c360-ira-panel-subtitle">{{ $overview['subtitle'] ?? 'Case intelligence' }}</p>
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

    <x-c360.ira-v2-signal-bar :signals="$signals" />

    <div class="c360-ira-panel-body c360-ira-v2-body"
         data-ira-summary-content
         data-ira-summary-en='@json($summaryPayload)'>

        @if($storySections !== [])
            <section class="c360-ira-panel-section c360-ira-v2-story" aria-labelledby="ira-v2-story">
                <h3 class="c360-ira-panel-section-title" id="ira-v2-story">Case story</h3>
                <div class="c360-ira-v2-story-grid">
                    @foreach($storySections as $section)
                        <div class="c360-ira-v2-story-section">
                            <h4 class="c360-ira-v2-story-title">{{ $section['title'] }}</h4>
                            <ul class="c360-ira-v2-story-list">
                                @foreach($section['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @elseif($narrativeHtml !== '')
            <section class="c360-ira-panel-section c360-ira-narrative" aria-labelledby="ira-v2-narrative">
                <h3 class="c360-ira-panel-section-title" id="ira-v2-narrative">Overview</h3>
                <p class="c360-ira-narrative-text"
                   data-ira-summary-block="executive"
                   data-ira-summary-mode="narrative">
                    {!! $narrativeHtml !!}
                </p>
            </section>
        @endif

        @if($communicationBriefing !== '')
            <section class="c360-ira-panel-section" aria-labelledby="ira-v2-comms">
                <h3 class="c360-ira-panel-section-title" id="ira-v2-comms">Communication</h3>
                <p class="c360-ira-v2-comms-brief">{{ $communicationBriefing }}</p>
            </section>
        @endif

        @if($actionCenter !== null)
            <x-c360.action-center
                :actionCenter="$actionCenter"
                :incident="$incident"
                class="c360-ira-panel-section c360-ira-panel-section--action"
            />
        @else
            <section class="c360-ira-panel-section c360-ira-panel-section--action c360-ira-next-action"
                     aria-labelledby="ira-v2-action">
                <h3 class="c360-ira-panel-section-title" id="ira-v2-action">Next best action</h3>
                <p class="c360-ira-panel-action-text" data-ira-summary-block="recommendation">
                    {{ $action['text'] ?? '' }}
                </p>
            </section>
        @endif

        @if($openQuestions !== [])
            <section class="c360-ira-panel-section" aria-labelledby="ira-v2-gaps">
                <h3 class="c360-ira-panel-section-title" id="ira-v2-gaps">Missing information</h3>
                <ul class="c360-ira-v2-story-list">
                    @foreach($openQuestions as $question)
                        <li>{{ $question }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($blockers !== [] || $risks !== [])
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
                                <span class="c360-ira-panel-risk-title">{{ $risk['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
        @endif

        <details class="c360-ira-panel-details" aria-labelledby="ira-v2-why">
            <summary id="ira-v2-why">Why IRA thinks this</summary>
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
                        @endphp
                        <li @class(['c360-ira-panel-evidence-item', 'c360-ira-panel-evidence-item--'.$tone]) role="listitem">
                            <span class="c360-ira-panel-evidence-icon" aria-hidden="true">{{ $icon }}</span>
                            <span class="c360-ira-panel-evidence-copy">
                                <span class="c360-ira-panel-evidence-title">{{ $item['title'] }}</span>
                                @if(filled($item['source'] ?? null))
                                    <span class="c360-ira-panel-evidence-source">{{ $item['source'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if(filled($overview['opinion'] ?? null))
                <p class="c360-ira-panel-opinion" data-ira-summary-block="opinion">
                    {{ $overview['opinion'] }}
                </p>
            @endif
        </details>

        <p class="c360-ira-v2-evidence-note">
            Business Timeline and channel logs remain available as supporting evidence in the Timeline tab.
        </p>
    </div>
</section>
