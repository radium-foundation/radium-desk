@props([
    'workbench',
    'incident',
])

@php
    $confidenceClass = 'customer-360-ai-confidence-banner--'.$workbench->confidenceLevel->value;
@endphp

<section id="customer-360-ai-workbench"
         class="customer-360-ai-workbench"
         data-ai-workbench-root
         data-ai-workbench-incident-id="{{ $incident->id }}"
         data-ai-workbench-refresh-url="{{ route('dashboard.service-cases.customer-360.ai-workbench', $incident) }}"
         data-ai-workbench-audit-url="{{ route('dashboard.service-cases.customer-360.ai-workbench.audit', $incident) }}"
         aria-labelledby="customer-360-ai-workbench-heading">
    <div class="customer-360-ai-workbench-header">
        <div>
            <h2 class="customer-360-section-title" id="customer-360-ai-workbench-heading">
                <i class="bi bi-tools" aria-hidden="true"></i>
                IRA Workspace
            </h2>
            <p class="customer-360-ai-workbench-subtitle">Turn recommendations into operator actions. Nothing executes automatically.</p>
        </div>
        <div class="customer-360-ai-workbench-header-actions">
            <span class="customer-360-ai-badge">Operator approved</span>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-ai-workbench-refresh
                    data-artifact-key="workbench">
                Refresh
            </button>
        </div>
    </div>

    <div class="customer-360-ai-confidence-banner {{ $confidenceClass }}">
        <span class="customer-360-ai-confidence-banner-label">IRA Confidence</span>
        <strong>{{ $workbench->confidenceLevel->label() }}</strong>
        <span class="customer-360-ai-confidence-banner-score">{{ $workbench->confidenceScore }}%</span>
    </div>

    <p class="customer-360-ai-workbench-confidence-note">{{ $workbench->confidenceExplanation }}</p>
    <p class="customer-360-ai-workbench-scenario">
        Scenario: <strong>{{ $workbench->scenarioLabel }}</strong>
        <span class="text-muted">· Powered by {{ $workbench->providerName }}</span>
    </p>

    <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
        <h3 class="customer-360-ai-card-title">Suggested Customer Reply</h3>
        <div class="customer-360-ai-workbench-channel-tabs" role="tablist" aria-label="Reply channels">
            @foreach($workbench->customerReplies as $index => $reply)
                <button type="button"
                        class="customer-360-ai-workbench-channel-tab @if($index === 0) is-active @endif"
                        role="tab"
                        aria-selected="{{ $index === 0 ? 'true' : 'false' }}"
                        data-ai-workbench-reply-tab="{{ $reply['key'] }}">
                    {{ $reply['channel_label'] }}
                </button>
            @endforeach
        </div>

        @foreach($workbench->customerReplies as $index => $reply)
            <div class="customer-360-ai-workbench-reply-pane @if($index !== 0) d-none @endif"
                 data-ai-workbench-reply-pane="{{ $reply['key'] }}"
                 role="tabpanel">
                <textarea class="form-control customer-360-ai-workbench-editor"
                          rows="5"
                          readonly
                          data-ai-workbench-editor="{{ $reply['key'] }}">{{ $reply['content'] }}</textarea>
                <p class="customer-360-ai-workbench-explanation">{{ $reply['explanation'] }}</p>
                <div class="customer-360-ai-workbench-actions">
                    <button type="button"
                            class="btn btn-sm btn-outline-primary"
                            data-ai-workbench-copy
                            data-artifact-key="{{ $reply['key'] }}">
                        Copy
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            data-ai-workbench-insert
                            data-artifact-key="{{ $reply['key'] }}"
                            data-insert-target="composer">
                        Insert into editor
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-link"
                            data-ai-workbench-refresh
                            data-artifact-key="{{ $reply['key'] }}">
                        Refresh suggestion
                    </button>
                </div>
            </div>
        @endforeach
    </article>

    <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
        <h3 class="customer-360-ai-card-title">Suggested Internal Note</h3>
        <textarea class="form-control customer-360-ai-workbench-editor"
                  rows="4"
                  readonly
                  data-ai-workbench-editor="internal_note">{{ $workbench->internalNote['content'] }}</textarea>
        <p class="customer-360-ai-workbench-explanation">{{ $workbench->internalNote['explanation'] }}</p>
        <div class="customer-360-ai-workbench-actions">
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-ai-workbench-copy
                    data-artifact-key="internal_note">
                Copy
            </button>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-ai-workbench-insert
                    data-artifact-key="internal_note"
                    data-insert-target="remark">
                Insert into editor
            </button>
            <button type="button"
                    class="btn btn-sm btn-link"
                    data-ai-workbench-refresh
                    data-artifact-key="internal_note">
                Refresh suggestion
            </button>
        </div>
    </article>

    <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
        <h3 class="customer-360-ai-card-title">Suggested Checklist</h3>
        <ul class="customer-360-ai-workbench-checklist">
            @foreach($workbench->checklist as $item)
                <li>
                    <label class="customer-360-ai-workbench-checklist-item">
                        <input type="checkbox" disabled>
                        <span>{{ $item['label'] }}</span>
                    </label>
                    <span class="customer-360-ai-workbench-checklist-note">{{ $item['explanation'] }}</span>
                </li>
            @endforeach
        </ul>
        <textarea class="d-none"
                  readonly
                  data-ai-workbench-editor="checklist">@foreach($workbench->checklist as $item)☐ {{ $item['label'] }}
@endforeach</textarea>
        <div class="customer-360-ai-workbench-actions">
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-ai-workbench-copy
                    data-artifact-key="checklist">
                Copy
            </button>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-ai-workbench-insert
                    data-artifact-key="checklist"
                    data-insert-target="remark">
                Insert into editor
            </button>
            <button type="button"
                    class="btn btn-sm btn-link"
                    data-ai-workbench-refresh
                    data-artifact-key="checklist">
                Refresh suggestion
            </button>
        </div>
    </article>

    <article class="customer-360-ai-card customer-360-ai-card--wide customer-360-ai-card--section">
        <h3 class="customer-360-ai-card-title">Suggested Next Workflow</h3>
        <ul class="customer-360-ai-workbench-workflow-list">
            @foreach($workbench->workflowSuggestions as $workflow)
                <li class="customer-360-ai-workbench-workflow-item">
                    <strong>{{ $workflow['label'] }}</strong>
                    <span>{{ $workflow['description'] }}</span>
                    <span class="customer-360-ai-workbench-explanation">{{ $workflow['explanation'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="customer-360-ai-text customer-360-ai-text--muted">Recommendations only. Use existing workspace actions to proceed manually.</p>
    </article>
</section>
