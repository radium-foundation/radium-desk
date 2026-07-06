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
    $telegramSummary = $telegramTotal === 0
        ? 'No team members configured'
        : sprintf('%s%% team connectivity', number_format($telegramTotal > 0 ? ($telegramConnected / $telegramTotal) * 100 : 0, 0));

    $badgeClassToStatus = [
        'success' => 'healthy',
        'danger' => 'danger',
        'warning' => 'warning',
        'secondary' => 'info',
    ];

    $systems = array_values(array_filter([
        [
            'id' => 'cashfree',
            'label' => 'Cashfree',
            'healthy' => $cashfreeHealthy,
            'summary' => $cashfreeHealthy
                ? '✅ Healthy'
                : ($cashfreeHealth['detail'] ?? 'Needs attention'),
            'badge_class' => $cashfreeHealth['badge_class'] ?? ($cashfreeHealthy ? 'success' : 'danger'),
            'status_class' => $badgeClassToStatus[$cashfreeHealth['badge_class'] ?? ($cashfreeHealthy ? 'success' : 'danger')] ?? 'info',
            'status_label' => $cashfreeHealth['status_label'] ?? ($cashfreeHealthy ? 'Healthy' : 'Needs attention'),
            'lazy_section' => 'cashfree_health',
        ],
        $radiumBoxEnabled ? [
            'id' => 'radiumbox',
            'label' => 'RadiumBox',
            'healthy' => $radiumBoxHealthy,
            'summary' => $radiumBoxHealthy
                ? '✅ Healthy'
                : sprintf(
                    '%s failed, %s pending sync(s)',
                    number_format((int) ($radiumBoxHealth['failed_syncs'] ?? 0)),
                    number_format((int) ($radiumBoxHealth['pending_syncs'] ?? 0)),
                ),
            'badge_class' => $radiumBoxHealthy ? 'success' : 'warning',
            'status_class' => $radiumBoxHealthy ? 'healthy' : 'warning',
            'status_label' => $radiumBoxHealthy ? 'Healthy' : 'Needs attention',
            'lazy_section' => 'health_radiumbox',
        ] : null,
        [
            'id' => 'telegram',
            'label' => 'Telegram',
            'healthy' => $telegramHealthy,
            'summary' => $telegramHealthy ? $telegramSummary : sprintf('%s of %s connected', number_format($telegramConnected), number_format($telegramTotal)),
            'badge_class' => $telegramHealthy ? 'success' : 'warning',
            'status_class' => $telegramHealthy ? 'healthy' : 'warning',
            'status_label' => $telegramHealthy ? 'Healthy' : 'Needs attention',
            'lazy_section' => 'health_telegram',
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
            @endphp
            <div class="accordion-item border-0 shadow-sm mb-2 operations-card-hover">
                <h3 class="accordion-header" id="operations-health-heading-{{ $system['id'] }}">
                    <button
                        class="accordion-button operations-health-accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $collapseId }}"
                        aria-expanded="false"
                        aria-controls="{{ $collapseId }}"
                        id="operations-health-trigger-{{ $system['id'] }}"
                    >
                        <span class="operations-health-accordion-title">{{ $system['label'] }}</span>
                        <span class="operations-health-accordion-summary text-muted">{{ $system['summary'] }}</span>
                        <span @class(['status-badge', 'status-' . ($system['status_class'] ?? 'info'), 'ms-auto', 'me-2'])>{{ $system['status_label'] }}</span>
                    </button>
                </h3>
                <div
                    id="{{ $collapseId }}"
                    class="accordion-collapse collapse"
                    aria-labelledby="operations-health-heading-{{ $system['id'] }}"
                    data-bs-parent="#operations-health-accordion"
                    data-operations-lazy-section="{{ $system['lazy_section'] }}"
                    data-operations-lazy-loaded="false"
                >
                    <div class="accordion-body pt-0" id="operations-health-detail-{{ $system['id'] }}">
                        <p class="text-muted small mb-0 operations-health-collapsed-hint">Expand to load {{ $system['label'] }} details.</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
