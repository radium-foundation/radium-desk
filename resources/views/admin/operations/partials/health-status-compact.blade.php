@props([
    'cashfreeHealth' => [],
    'radiumBoxHealth' => [],
    'teamTelegramStatus' => [],
])

@php
    $cashfreeHealthy = (bool) ($cashfreeHealth['is_healthy'] ?? true);
    $radiumBoxEnabled = (bool) ($radiumBoxHealth['enabled'] ?? false);
    $radiumBoxIssues = $radiumBoxEnabled && (
        ((int) ($radiumBoxHealth['failed_syncs'] ?? 0)) > 0
        || ((int) ($radiumBoxHealth['pending_syncs'] ?? 0)) > 0
    );
    $radiumBoxHealthy = ! $radiumBoxEnabled || ! $radiumBoxIssues;

    $telegramConnected = collect($teamTelegramStatus)->where('connected', true)->count();
    $telegramTotal = count($teamTelegramStatus);
    $telegramHealthy = $telegramTotal === 0 || $telegramConnected === $telegramTotal;

    $systems = array_values(array_filter([
        [
            'id' => 'cashfree',
            'label' => 'Cashfree',
            'healthy' => $cashfreeHealthy,
            'summary' => $cashfreeHealthy
                ? 'Payment webhooks healthy'
                : ($cashfreeHealth['detail'] ?? 'Needs attention'),
            'badge_class' => $cashfreeHealth['badge_class'] ?? ($cashfreeHealthy ? 'success' : 'danger'),
            'status_label' => $cashfreeHealth['status_label'] ?? ($cashfreeHealthy ? 'Healthy' : 'Needs attention'),
            'partial' => 'admin.operations.partials.cashfree-health',
            'partial_data' => ['health' => $cashfreeHealth],
        ],
        $radiumBoxEnabled ? [
            'id' => 'radiumbox',
            'label' => 'RadiumBox',
            'healthy' => $radiumBoxHealthy,
            'summary' => $radiumBoxHealthy
                ? sprintf('%s%% success rate (24h)', number_format((float) ($radiumBoxHealth['success_rate_24h'] ?? 0), 1))
                : sprintf(
                    '%s failed, %s pending sync(s)',
                    number_format((int) ($radiumBoxHealth['failed_syncs'] ?? 0)),
                    number_format((int) ($radiumBoxHealth['pending_syncs'] ?? 0)),
                ),
            'badge_class' => $radiumBoxHealthy ? 'success' : 'warning',
            'status_label' => $radiumBoxHealthy ? 'Healthy' : 'Needs attention',
            'partial' => 'admin.operations.partials.radiumbox-health',
            'partial_data' => ['health' => $radiumBoxHealth],
        ] : null,
        [
            'id' => 'telegram',
            'label' => 'Telegram',
            'healthy' => $telegramHealthy,
            'summary' => $telegramTotal === 0
                ? 'No team members configured'
                : sprintf('%s of %s team member(s) connected', number_format($telegramConnected), number_format($telegramTotal)),
            'badge_class' => $telegramHealthy ? 'success' : 'warning',
            'status_label' => $telegramHealthy ? 'Healthy' : 'Needs attention',
            'partial' => 'admin.operations.partials.team-telegram-status',
            'partial_data' => ['members' => $teamTelegramStatus],
        ],
    ]));
@endphp

<section class="operations-health-compact mb-4" aria-labelledby="operations-health-compact-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 id="operations-health-compact-heading" class="h6 mb-0 text-uppercase text-muted fw-semibold">Integration Health</h2>
        <span class="text-muted small">Expand for details</span>
    </div>

    <div class="accordion operations-health-accordion" id="operations-health-accordion">
        @foreach ($systems as $system)
            @php
                $collapseId = 'operations-health-'.$system['id'];
                $isExpanded = ! $system['healthy'];
            @endphp
            <div class="accordion-item border-0 shadow-sm mb-2">
                <h3 class="accordion-header" id="operations-health-heading-{{ $system['id'] }}">
                    <button
                        class="accordion-button operations-health-accordion-button {{ $isExpanded ? '' : 'collapsed' }}"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $collapseId }}"
                        aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                        aria-controls="{{ $collapseId }}"
                        id="operations-health-trigger-{{ $system['id'] }}"
                    >
                        <span class="operations-health-accordion-title">{{ $system['label'] }}</span>
                        <span class="operations-health-accordion-summary text-muted">{{ $system['summary'] }}</span>
                        <span class="badge bg-{{ $system['badge_class'] }} ms-auto me-2">{{ $system['status_label'] }}</span>
                    </button>
                </h3>
                <div
                    id="{{ $collapseId }}"
                    class="accordion-collapse collapse {{ $isExpanded ? 'show' : '' }}"
                    aria-labelledby="operations-health-heading-{{ $system['id'] }}"
                    data-bs-parent="#operations-health-accordion"
                >
                    <div class="accordion-body pt-0">
                        @include($system['partial'], $system['partial_data'])
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
