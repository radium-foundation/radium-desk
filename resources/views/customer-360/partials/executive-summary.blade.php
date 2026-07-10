@php
    $summaryLines = $executiveSummary->executiveSummary ?? [];
    $summaryPayload = [
        'executive_summary' => $summaryLines,
        'opinion' => $executiveSummary->opinion,
        'recommendation' => $executiveSummary->recommendation,
    ];
    $translateUrl = route('dashboard.service-cases.customer-360.executive-summary.translate', $incident);
@endphp

@if($executiveSummary)
<section class="customer-360-executive-summary"
         data-customer-360-section="executive-summary"
         data-ira-executive-summary
         data-ira-translate-url="{{ $translateUrl }}"
         aria-labelledby="customer-360-executive-summary-heading">
    <div class="customer-360-executive-summary-header">
        <div class="customer-360-executive-summary-title-wrap">
            <h2 class="customer-360-executive-summary-title" id="customer-360-executive-summary-heading">
                <span aria-hidden="true">🧠</span> IRA Executive Summary
            </h2>
            <button type="button"
                    class="btn btn-link btn-sm customer-360-executive-summary-lang"
                    data-ira-summary-lang-toggle
                    aria-pressed="false"
                    aria-label="Toggle Hindi translation">
                🌐 हिन्दी
            </button>
        </div>
        <span class="customer-360-ai-badge">Read Only</span>
    </div>

    <div class="customer-360-executive-summary-body"
         data-ira-summary-content
         data-ira-summary-en='@json($summaryPayload)'>
        @if($executiveSummary->serialInsight?->isActionable())
            <div class="customer-360-executive-summary-section customer-360-serial-insight"
                 data-ira-serial-insight>
                <h3 class="customer-360-executive-summary-label">Serial Intelligence</h3>
                <p class="customer-360-executive-summary-meta">
                    Status: {{ $executiveSummary->serialInsight->status->label() }}
                    · Confidence: {{ $executiveSummary->serialInsight->confidence->label() }}
                </p>
                <p class="customer-360-executive-summary-line">{{ $executiveSummary->serialInsight->explanation }}</p>
                @if(filled($executiveSummary->serialInsight->suggestedAction))
                    <p class="customer-360-executive-summary-text">
                        "{{ $executiveSummary->serialInsight->suggestedAction }}"
                    </p>
                @endif
                @if(in_array($executiveSummary->serialInsight->status, [
                    \App\Enums\SerialInsightStatus::Suspicious,
                    \App\Enums\SerialInsightStatus::Warning,
                ], true) && ($canRequestCorrectSerial ?? false))
                    <button type="button"
                            class="btn btn-sm btn-primary mt-2"
                            data-workspace-trigger="request-correct-serial"
                            data-workspace-incident-id="{{ $incident->id }}"
                            data-workspace-context="customer">
                        Send request
                    </button>
                @endif
            </div>
        @endif

        <div class="customer-360-executive-summary-block" data-ira-summary-block="executive">
            @foreach($summaryLines as $line)
                <p class="customer-360-executive-summary-line">{{ $line }}</p>
            @endforeach
        </div>

        <div class="customer-360-executive-summary-section">
            <h3 class="customer-360-executive-summary-label">IRA Opinion</h3>
            <p class="customer-360-executive-summary-text" data-ira-summary-block="opinion">
                "{{ $executiveSummary->opinion }}"
            </p>
        </div>

        <div class="customer-360-executive-summary-section">
            <h3 class="customer-360-executive-summary-label">IRA Recommendation</h3>
            <p class="customer-360-executive-summary-text" data-ira-summary-block="recommendation">
                "{{ $executiveSummary->recommendation }}"
            </p>
        </div>
    </div>
</section>
@endif
