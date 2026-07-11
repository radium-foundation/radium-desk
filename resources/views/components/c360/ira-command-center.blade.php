@props([
    'executiveSummary',
    'incident',
    'canRequestCorrectSerial' => false,
    'translateUrl' => null,
])

@php
    use App\Enums\SerialInsightStatus;
    use App\Enums\ServiceCaseSlaStatus;

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
    $serialInsight = $executiveSummary->serialInsight;
    $confidenceLevel = $serialInsight?->confidence->value ?? 'medium';
    $confidenceLabel = $serialInsight?->confidence->label() ?? 'Medium';

    $evidenceItems = [];

    if (filled($incident->order?->product_name)) {
        $evidenceItems[] = ['title' => 'Product matched', 'source' => 'Order', 'tone' => 'positive'];
    }

    if (filled($incident->order?->serial_number)) {
        $evidenceItems[] = ['title' => 'Device identified', 'source' => 'RadiumBox', 'tone' => 'positive'];
    }

    if ($serialInsight !== null) {
        $evidenceItems[] = match ($serialInsight->status) {
            SerialInsightStatus::Valid => ['title' => 'Serial verified', 'source' => 'IRA', 'tone' => 'positive'],
            SerialInsightStatus::Suspicious, SerialInsightStatus::Warning => ['title' => 'Serial mismatch', 'source' => 'IRA', 'tone' => 'warning'],
            SerialInsightStatus::Missing => ['title' => 'Serial missing', 'source' => 'IRA', 'tone' => 'negative'],
            default => ['title' => $serialInsight->status->label(), 'source' => 'IRA', 'tone' => 'warning'],
        };
    }

    foreach ($whyLines as $line) {
        $lower = strtolower($line);

        if (str_contains($lower, 'payment') && str_contains($lower, 'receiv')) {
            $evidenceItems[] = ['title' => 'Payment received', 'source' => 'Timeline', 'tone' => 'positive'];
        } elseif (str_contains($lower, 'whatsapp') && (str_contains($lower, 'replied') || str_contains($lower, 'respond'))) {
            $evidenceItems[] = ['title' => 'Customer replied', 'source' => 'WhatsApp', 'tone' => 'positive'];
        } elseif (str_contains($lower, 'email') && str_contains($lower, 'replied')) {
            $evidenceItems[] = ['title' => 'Customer replied', 'source' => 'Email', 'tone' => 'positive'];
        } elseif (str_contains($lower, 'waiting')) {
            $evidenceItems[] = ['title' => 'Waiting state active', 'source' => 'IRA', 'tone' => 'warning'];
        }
    }

    if ($incident->activeWaitingState !== null) {
        $evidenceItems[] = ['title' => 'Waiting state active', 'source' => 'IRA', 'tone' => 'warning'];
    }

    if ($incident->slaStatus() === ServiceCaseSlaStatus::Overdue) {
        $evidenceItems[] = ['title' => 'SLA breached', 'source' => 'Timeline', 'tone' => 'negative'];
    } elseif ($incident->slaStatus() === ServiceCaseSlaStatus::Warning) {
        $evidenceItems[] = ['title' => 'SLA warning', 'source' => 'Timeline', 'tone' => 'warning'];
    }

    $evidenceItems = collect($evidenceItems)
        ->unique(fn (array $item) => $item['title'].'|'.$item['source'])
        ->values()
        ->all();

    $signalCount = count($evidenceItems);
    $confidencePercent = match ($confidenceLevel) {
        'high' => min(95, 82 + ($signalCount * 2)),
        'low' => max(28, 35 + $signalCount),
        default => min(88, 58 + ($signalCount * 3)),
    };

    $hasSerialAction = $serialInsight?->isActionable()
        && in_array($serialInsight->status, [
            SerialInsightStatus::Suspicious,
            SerialInsightStatus::Warning,
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
            IRA command center
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
                Recommendation
            </h3>
            <p class="c360-ira-recommendation-text" data-ira-summary-block="recommendation">
                {{ $recommendation }}
            </p>
        </div>

        <div class="c360-ira-section c360-ira-section--action">
            <h3 class="c360-ira-section-label">Primary action</h3>
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
            <x-c360.ira-confidence
                :level="$confidenceLevel"
                :label="$confidenceLabel"
                :percent="$confidencePercent"
                :signal-count="$signalCount"
            />
        </div>

        @if($whyLines !== [])
            <div class="c360-ira-section">
                <h3 class="c360-ira-section-label">Why this recommendation</h3>
                <ul class="c360-ira-why-list" data-ira-summary-block="executive">
                    @foreach($whyLines as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($journeySteps !== [])
            <details class="c360-ira-collapse" data-c360-ira-collapse>
                <summary class="c360-ira-collapse-summary">
                    <span>Journey</span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="c360-ira-collapse-body">
                    <x-c360.customer-journey-tracker :milestones="$journeySteps" />
                </div>
            </details>
        @endif

        @if($evidenceItems !== [])
            <details class="c360-ira-collapse" data-c360-ira-collapse>
                <summary class="c360-ira-collapse-summary">
                    <span>Evidence</span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="c360-ira-collapse-body">
                    <x-c360.ira-evidence-panel :items="$evidenceItems" />
                </div>
            </details>
        @endif

        <details class="c360-ira-collapse" data-c360-ira-collapse>
            <summary class="c360-ira-collapse-summary">
                <span>Explain</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="c360-ira-collapse-body c360-ira-explain-body">
                @if($serialInsight?->isActionable())
                    <div class="c360-ira-explain-block" data-ira-serial-insight>
                        <x-c360.status-banner
                            :variant="match ($serialInsight->status->value) {
                                'valid' => 'success',
                                'suspicious', 'warning' => 'warning',
                                'missing', 'pending' => 'info',
                                default => 'info',
                            }"
                            :icon="match ($serialInsight->status->value) {
                                'valid' => '✓',
                                'suspicious', 'warning' => '⚠',
                                'missing' => '✖',
                                default => 'ⓘ',
                            }">
                            {{ $serialInsight->status->label() }}
                            · {{ $serialInsight->confidence->label() }} confidence
                        </x-c360.status-banner>
                        <p class="c360-ira-explain-text">{{ $serialInsight->explanation }}</p>
                        @if(filled($serialInsight->suggestedAction))
                            <p class="c360-ira-explain-text c360-ira-explain-text--muted">
                                {{ $serialInsight->suggestedAction }}
                            </p>
                        @endif
                    </div>
                @endif
                <div class="c360-ira-explain-block">
                    <h4 class="c360-ira-explain-label">IRA opinion</h4>
                    <p class="c360-ira-explain-text" data-ira-summary-block="opinion">
                        {{ trim($executiveSummary->opinion, " \t\n\r\0\x0B\"'") }}
                    </p>
                </div>
            </div>
        </details>

        <details class="c360-ira-collapse c360-ira-collapse--muted" data-c360-ira-collapse>
            <summary class="c360-ira-collapse-summary">
                <span>Operational memory</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="c360-ira-collapse-body">
                <p class="c360-ira-memory-placeholder mb-0">
                    Prior case context and operator notes will surface here in a future release.
                </p>
            </div>
        </details>
    </div>
</section>
