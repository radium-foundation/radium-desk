@props([
    'executiveSummary',
    'incident',
    'canRequestCorrectSerial' => false,
    'translateUrl' => null,
])

@php
    $summaryLines = $executiveSummary->executiveSummary ?? [];
    $summaryPayload = [
        'executive_summary' => $summaryLines,
        'opinion' => $executiveSummary->opinion,
        'recommendation' => $executiveSummary->recommendation,
    ];

    $journeyLine = collect($summaryLines)->first(
        fn (string $line): bool => str_starts_with($line, 'Customer journey:'),
    );
    $journeySteps = [];
    if (is_string($journeyLine)) {
        $journeyText = trim(str_replace('Customer journey:', '', $journeyLine));
        $journeySteps = array_values(array_filter(array_map('trim', explode('→', $journeyText))));
    }

    $whyLines = collect($summaryLines)
        ->reject(fn (string $line): bool => str_starts_with($line, 'Customer journey:'))
        ->reject(fn (string $line): bool => str_contains(strtolower($line), 'confidence:'))
        ->values()
        ->all();

    $recommendation = trim($executiveSummary->recommendation, " \t\n\r\0\x0B\"'");
    $confidenceLevel = $executiveSummary->serialInsight?->confidence->value ?? 'medium';
    $confidenceLabel = $executiveSummary->serialInsight?->confidence->label() ?? 'Medium';
    $confidencePercent = match ($confidenceLevel) {
        'high' => 85,
        'low' => 35,
        default => 60,
    };

    $hasSerialAction = $executiveSummary->serialInsight?->isActionable()
        && in_array($executiveSummary->serialInsight->status, [
            \App\Enums\SerialInsightStatus::Suspicious,
            \App\Enums\SerialInsightStatus::Warning,
        ], true)
        && ($canRequestCorrectSerial ?? false);
@endphp

<section {{ $attributes->merge(['class' => 'c360-ira-command-center']) }}
         data-customer-360-section="executive-summary"
         data-ira-executive-summary
         @if($translateUrl) data-ira-translate-url="{{ $translateUrl }}" @endif
         aria-labelledby="c360-ira-command-center-heading">
    <div class="c360-ira-command-center-glow" aria-hidden="true"></div>

    <div class="c360-ira-command-center-header">
        <h2 class="c360-ira-command-center-heading" id="c360-ira-command-center-heading">
            <i class="bi bi-stars c360-ira-sparkle" aria-hidden="true"></i>
            IRA Command Center
        </h2>
        <div class="c360-ira-command-center-header-actions">
            @if($translateUrl)
                <button type="button"
                        class="c360-ira-lang-toggle"
                        data-ira-summary-lang-toggle
                        aria-pressed="false"
                        aria-label="Toggle Hindi translation">
                    हिन्दी
                </button>
            @endif
            <span class="c360-ira-readonly-badge">AI · Read only</span>
        </div>
    </div>

    <div class="c360-ira-command-center-body"
         data-ira-summary-content
         data-ira-summary-en='@json($summaryPayload)'>
        <div class="c360-ira-section">
            <h3 class="c360-ira-section-label">
                <i class="bi bi-stars" aria-hidden="true"></i>
                IRA Recommendation
            </h3>
            <p class="c360-ira-recommendation-text" data-ira-summary-block="recommendation">
                {{ $recommendation }}
            </p>
        </div>

        <div class="c360-ira-section c360-ira-section--action">
            <h3 class="c360-ira-section-label">Primary Action</h3>
            @if($hasSerialAction)
                <button type="button"
                        class="c360-ira-primary-action"
                        data-workspace-trigger="request-correct-serial"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="customer">
                    <i class="bi bi-send" aria-hidden="true"></i>
                    Send request
                </button>
            @else
                <div class="c360-ira-primary-action c360-ira-primary-action--display" role="status">
                    <i class="bi bi-lightning-charge" aria-hidden="true"></i>
                    <span>{{ $recommendation }}</span>
                </div>
            @endif
        </div>

        <div class="c360-ira-section">
            <div class="c360-ira-confidence-header">
                <h3 class="c360-ira-section-label mb-0">Confidence</h3>
                <span @class([
                    'c360-ira-confidence-label',
                    'c360-ira-confidence-label--' . $confidenceLevel,
                ])>{{ $confidenceLabel }}</span>
            </div>
            <div class="c360-ira-confidence-bar"
                 role="progressbar"
                 aria-valuenow="{{ $confidencePercent }}"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-label="IRA confidence level">
                <span class="c360-ira-confidence-fill c360-ira-confidence-fill--{{ $confidenceLevel }}"
                      style="width: {{ $confidencePercent }}%"></span>
            </div>
        </div>

        @if($whyLines !== [])
            <div class="c360-ira-section">
                <h3 class="c360-ira-section-label">Why?</h3>
                <ul class="c360-ira-why-list" data-ira-summary-block="executive">
                    @foreach($whyLines as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($journeySteps !== [])
            <div class="c360-ira-section">
                <h3 class="c360-ira-section-label">Journey</h3>
                <x-c360.customer-journey-tracker :milestones="$journeySteps" />
            </div>
        @endif

        <details class="c360-ira-explain" data-c360-ira-explain>
            <summary class="c360-ira-explain-summary">
                <span>Explain</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="c360-ira-explain-body">
                @if($executiveSummary->serialInsight?->isActionable())
                    <div class="c360-ira-explain-block" data-ira-serial-insight>
                        <x-c360.status-banner
                            :variant="match ($executiveSummary->serialInsight->status->value) {
                                'valid' => 'success',
                                'suspicious', 'warning' => 'warning',
                                'missing', 'pending' => 'info',
                                default => 'info',
                            }"
                            :icon="match ($executiveSummary->serialInsight->status->value) {
                                'valid' => '✓',
                                'suspicious', 'warning' => '⚠',
                                'missing' => '✖',
                                default => 'ⓘ',
                            }">
                            {{ $executiveSummary->serialInsight->status->label() }}
                            · {{ $executiveSummary->serialInsight->confidence->label() }} confidence
                        </x-c360.status-banner>
                        <p class="c360-ira-explain-text">{{ $executiveSummary->serialInsight->explanation }}</p>
                        @if(filled($executiveSummary->serialInsight->suggestedAction))
                            <p class="c360-ira-explain-text c360-ira-explain-text--muted">
                                {{ $executiveSummary->serialInsight->suggestedAction }}
                            </p>
                        @endif
                    </div>
                @endif
                <div class="c360-ira-explain-block">
                    <h4 class="c360-ira-explain-label">IRA Opinion</h4>
                    <p class="c360-ira-explain-text" data-ira-summary-block="opinion">
                        {{ trim($executiveSummary->opinion, " \t\n\r\0\x0B\"'") }}
                    </p>
                </div>
            </div>
        </details>
    </div>
</section>
