@php
    use App\Support\AppDateFormatter;

    $displayValue = fn (?string $value) => filled($value) ? $value : 'Not Available';
    $formatCurrency = fn (float $amount) => '₹'.number_format($amount, 2);
    $customerIntel = $aiAssistant->customerIntelligence;
    $deviceIntel = $aiAssistant->deviceIntelligence;
    $operationalIntel = $aiAssistant->operationalIntelligence;
    $businessIntel = $aiAssistant->businessIntelligence;
    $knowledge = $aiAssistant->knowledge;
@endphp

<section class="customer-360-section customer-360-ai-assistant"
         data-customer-360-section="ai-assistant"
         aria-labelledby="customer-360-ai-heading">
    <div class="customer-360-ai-header">
        <h2 class="customer-360-section-title" id="customer-360-ai-heading">
            <i class="bi bi-stars" aria-hidden="true"></i>
            IRA AI
        </h2>
        <span class="customer-360-ai-badge">Read only</span>
    </div>

    <p class="customer-360-ai-provider-note">
        Powered by <strong>{{ $aiAssistant->providerName }}</strong> provider (foundation mode).
    </p>

    <div class="customer-360-ai-grid">
        <article class="customer-360-ai-card customer-360-ai-card--wide">
            <h3 class="customer-360-ai-card-title">Customer Summary</h3>
            <p class="customer-360-ai-text">{{ $aiAssistant->customerSummary }}</p>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide">
            <h3 class="customer-360-ai-card-title">Incident Summary</h3>
            <p class="customer-360-ai-text customer-360-ai-text--multiline">{{ $aiAssistant->incidentSummary }}</p>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
            <h3 class="customer-360-ai-card-title">Customer Intelligence</h3>
            <dl class="customer-360-ai-dl">
                <div><dt>Lifetime Orders</dt><dd>{{ $customerIntel->lifetimeOrderCount }}</dd></div>
                <div><dt>Lifetime Repairs</dt><dd>{{ $customerIntel->lifetimeRepairCount }}</dd></div>
                <div><dt>Premium Status</dt><dd>{{ $customerIntel->isPremiumCustomer ? 'Premium' : 'Standard' }}</dd></div>
                <div><dt>Warranty History</dt><dd>{{ $customerIntel->warrantyHistorySummary }}</dd></div>
                <div><dt>Repeat Issue</dt><dd>{{ $customerIntel->repeatIssueDetected ? ($customerIntel->repeatIssueSummary ?? 'Detected') : 'None detected' }}</dd></div>
                <div><dt>Avg Repair Turnaround</dt><dd>{{ $customerIntel->averageRepairTurnaroundDays !== null ? $customerIntel->averageRepairTurnaroundDays.' days' : 'Not Available' }}</dd></div>
                <div><dt>Last Interaction</dt>
                    <dd>
                        @if($customerIntel->lastInteractionAt)
                            <time datetime="{{ $customerIntel->lastInteractionAt->toIso8601String() }}">
                                {{ AppDateFormatter::timelineRelative($customerIntel->lastInteractionAt) }}
                            </time>
                            @if(filled($customerIntel->lastInteractionSummary))
                                — {{ $customerIntel->lastInteractionSummary }}
                            @endif
                        @else
                            Not Available
                        @endif
                    </dd>
                </div>
                <div><dt>Outstanding Balance</dt><dd>{{ $formatCurrency($customerIntel->outstandingBalance) }}</dd></div>
                <div><dt>Payment Behaviour</dt><dd>{{ $customerIntel->paymentBehaviour }}</dd></div>
            </dl>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
            <h3 class="customer-360-ai-card-title">Device Intelligence</h3>
            <dl class="customer-360-ai-dl">
                <div><dt>Model</dt><dd>{{ $displayValue($deviceIntel->model) }}</dd></div>
                <div><dt>Category</dt><dd>{{ $displayValue($deviceIntel->category) }}</dd></div>
                <div><dt>Variant</dt><dd>{{ $displayValue($deviceIntel->variant) }}</dd></div>
                <div><dt>Serial Available</dt><dd>{{ $deviceIntel->serialAvailable ? 'Yes' : 'No' }}</dd></div>
                <div><dt>Repairs on Serial</dt><dd>{{ $deviceIntel->previousRepairsOnSerial }}</dd></div>
                <div><dt>Repairs on Model</dt><dd>{{ $deviceIntel->previousRepairsOnModel }}</dd></div>
                <div><dt>Common Failures</dt>
                    <dd>
                        @if($deviceIntel->commonFailurePatterns !== [])
                            {{ implode(', ', $deviceIntel->commonFailurePatterns) }}
                        @else
                            Not Available
                        @endif
                    </dd>
                </div>
                <div><dt>Parts Replaced</dt>
                    <dd>
                        @if($deviceIntel->partsFrequentlyReplaced !== [])
                            {{ implode(', ', $deviceIntel->partsFrequentlyReplaced) }}
                        @else
                            Not Available
                        @endif
                    </dd>
                </div>
            </dl>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
            <h3 class="customer-360-ai-card-title">Operational Intelligence</h3>
            <dl class="customer-360-ai-dl">
                <div><dt>Waiting State</dt><dd>{{ $operationalIntel->waitingState['reason_label'] ?? 'None' }}</dd></div>
                <div><dt>SLA State</dt><dd>{{ $operationalIntel->slaState }}</dd></div>
                <div><dt>Priority</dt><dd>{{ $operationalIntel->priority }}</dd></div>
                <div><dt>Assignment</dt><dd>{{ $displayValue($operationalIntel->assignment) }}</dd></div>
                <div><dt>Queue Position</dt><dd>{{ $operationalIntel->queuePosition ?? 'Not in queue' }}</dd></div>
                <div><dt>Automation Status</dt><dd>{{ $operationalIntel->automationStatus }}</dd></div>
                <div><dt>Timeline Summary</dt><dd>{{ $operationalIntel->timelineSummary }}</dd></div>
                <div><dt>Internal Remarks</dt><dd>{{ $operationalIntel->internalRemarksSummary }}</dd></div>
            </dl>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
            <h3 class="customer-360-ai-card-title">Business Intelligence</h3>
            <dl class="customer-360-ai-dl">
                <div><dt>Revenue from Customer</dt><dd>{{ $formatCurrency($businessIntel->revenueFromCustomer) }}</dd></div>
                <div><dt>Warranty Cost</dt><dd>{{ $formatCurrency($businessIntel->warrantyCost) }}</dd></div>
                <div><dt>Total Repair Value</dt><dd>{{ $formatCurrency($businessIntel->totalRepairValue) }}</dd></div>
                <div><dt>AMC / Service Plan</dt><dd>{{ $displayValue($businessIntel->amcServicePlan) }}</dd></div>
                <div><dt>Parts Cost History</dt><dd>{{ $formatCurrency($businessIntel->partsCostHistory) }}</dd></div>
            </dl>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
            <h3 class="customer-360-ai-card-title">IRA Knowledge</h3>
            <p class="customer-360-ai-text">{{ $knowledge->knowledgeSummary }}</p>
            <dl class="customer-360-ai-dl customer-360-ai-dl--knowledge">
                <div><dt>Similar Repairs</dt><dd>{{ $knowledge->similarRepairsCount() }}</dd></div>
                <div><dt>Common Resolution</dt><dd>{{ $displayValue($knowledge->commonResolution()) }}</dd></div>
                <div><dt>Previous Engineer</dt><dd>{{ $displayValue($knowledge->previousEngineer()) }}</dd></div>
                <div><dt>Average Resolution Time</dt><dd>{{ $knowledge->averageResolutionTimeDays() !== null ? $knowledge->averageResolutionTimeDays().' days' : 'Not Available' }}</dd></div>
                <div><dt>Historical Success Rate</dt><dd>{{ number_format($knowledge->historicalSuccessRate(), 1) }}%</dd></div>
                <div><dt>Repeat Failure %</dt><dd>{{ number_format($knowledge->repeatFailurePercent(), 1) }}%</dd></div>
                <div class="customer-360-ai-dl-full"><dt>Top Recommended Fixes</dt>
                    <dd>
                        @if($knowledge->topRecommendedFixes() !== [])
                            {{ implode(', ', $knowledge->topRecommendedFixes()) }}
                        @else
                            Not Available
                        @endif
                    </dd>
                </div>
            </dl>
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide">
            <h3 class="customer-360-ai-card-title">Risk Indicators</h3>
            @if($aiAssistant->riskIndicators !== [])
                <ul class="customer-360-ai-risk-list">
                    @foreach($aiAssistant->riskIndicators as $indicator)
                        <li class="customer-360-ai-risk-item customer-360-ai-risk-item--{{ $indicator->level->value }}">
                            <span class="customer-360-ai-risk-level">{{ $indicator->level->label() }}</span>
                            <span>{{ $indicator->label }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="customer-360-ai-text customer-360-ai-text--muted">No risk indicators identified.</p>
            @endif
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section"
                 aria-labelledby="customer-360-ira-confidence-heading">
            <h3 class="customer-360-ai-card-title" id="customer-360-ira-confidence-heading">IRA Confidence</h3>
            <div class="customer-360-ai-confidence-banner customer-360-ai-confidence-banner--{{ $aiAssistant->confidenceLevel->value }}">
                <span class="customer-360-ai-confidence-banner-label">IRA Confidence</span>
                <strong>{{ $aiAssistant->confidenceLevel->label() }}</strong>
                <span class="customer-360-ai-confidence-banner-score">{{ $aiAssistant->confidenceScore }}%</span>
            </div>
        </article>

        <article class="customer-360-ai-card">
            <h3 class="customer-360-ai-card-title">Warranty</h3>
            <p class="customer-360-ai-text">{{ $displayValue($aiAssistant->warrantyStatus) }}</p>
        </article>

        <article class="customer-360-ai-card">
            <h3 class="customer-360-ai-card-title">Payment</h3>
            @if($aiAssistant->paymentStatus)
                <p class="customer-360-ai-text">{{ $aiAssistant->paymentStatus['label'] }}</p>
                @if(filled($aiAssistant->paymentStatus['occurred_at'] ?? null))
                    <time class="customer-360-ai-meta"
                          datetime="{{ $aiAssistant->paymentStatus['occurred_at'] }}">
                        {{ AppDateFormatter::timelineRelative(\Illuminate\Support\Carbon::parse($aiAssistant->paymentStatus['occurred_at'])) }}
                    </time>
                @endif
            @else
                <p class="customer-360-ai-text customer-360-ai-text--muted">Not Available</p>
            @endif
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide">
            <h3 class="customer-360-ai-card-title">Suggested Next Actions</h3>
            @if($aiAssistant->suggestedNextActions !== [])
                <ul class="customer-360-ai-action-list">
                    @foreach($aiAssistant->suggestedNextActions as $action)
                        <li>
                            <span class="customer-360-ai-action-title">{{ $action->title }}</span>
                            @if(filled($action->description))
                                <span class="customer-360-ai-action-description">{{ $action->description }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="customer-360-ai-text customer-360-ai-text--muted">No actions suggested.</p>
            @endif
        </article>

        <article class="customer-360-ai-card customer-360-ai-card--wide">
            <h3 class="customer-360-ai-card-title">Suggested Customer Reply</h3>
            <blockquote class="customer-360-ai-reply">{{ $aiAssistant->suggestedCustomerReply }}</blockquote>
        </article>

        <article class="customer-360-ai-card">
            <h3 class="customer-360-ai-card-title">Classification</h3>
            <p class="customer-360-ai-text">{{ $aiAssistant->classification }}</p>
        </article>

        <article class="customer-360-ai-card">
            <h3 class="customer-360-ai-card-title">Estimated Resolution</h3>
            <p class="customer-360-ai-text">{{ $aiAssistant->estimatedResolution }}</p>
        </article>
    </div>

    @if(filled($aiAssistant->recommendationExplanation))
        <details class="customer-360-ai-explanation">
            <summary>Why this recommendation?</summary>
            <p>{{ $aiAssistant->recommendationExplanation }}</p>
        </details>
    @endif
</section>
