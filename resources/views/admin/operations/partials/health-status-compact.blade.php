@props([
    'cashfreeHealth' => [],
    'radiumBoxHealth' => [],
    'teamTelegramStatus' => [],
    'integrationHealth' => [],
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

    $statusClassMap = [
        'healthy' => 'healthy',
        'warning' => 'warning',
        'failed' => 'danger',
        'success' => 'healthy',
        'danger' => 'danger',
    ];

    $badgeClassToStatus = [
        'success' => 'healthy',
        'danger' => 'danger',
        'warning' => 'warning',
        'secondary' => 'info',
    ];

    $systems = [];

    $systems[] = [
        'id' => 'cashfree',
        'label' => 'Cashfree',
        'healthy' => $cashfreeHealthy,
        'detail' => $cashfreeHealthy
            ? null
            : ($cashfreeHealth['detail'] ?? 'Needs attention'),
        'status_class' => $badgeClassToStatus[$cashfreeHealth['badge_class'] ?? ($cashfreeHealthy ? 'success' : 'danger')] ?? 'info',
        'status_label' => $cashfreeHealth['status_label'] ?? ($cashfreeHealthy ? 'Healthy' : 'Needs attention'),
        'lazy_section' => 'cashfree_health',
    ];

    if ($radiumBoxEnabled) {
        $systems[] = [
            'id' => 'radiumbox',
            'label' => 'RadiumBox',
            'healthy' => $radiumBoxHealthy,
            'detail' => $radiumBoxHealthy
                ? null
                : sprintf(
                    '%s failed, %s pending sync(s)',
                    number_format((int) ($radiumBoxHealth['failed_syncs'] ?? 0)),
                    number_format((int) ($radiumBoxHealth['pending_syncs'] ?? 0)),
                ),
            'status_class' => $radiumBoxHealthy ? 'healthy' : 'warning',
            'status_label' => $radiumBoxHealthy ? 'Healthy' : 'Needs attention',
            'lazy_section' => 'health_radiumbox',
        ];
    }

    $systems[] = [
        'id' => 'telegram',
        'label' => 'Telegram',
        'healthy' => $telegramHealthy,
        'detail' => $telegramHealthy
            ? null
            : sprintf('%s of %s connected', number_format($telegramConnected), number_format($telegramTotal)),
        'status_class' => $telegramHealthy ? 'healthy' : 'warning',
        'status_label' => $telegramHealthy ? 'Healthy' : 'Needs attention',
        'lazy_section' => 'health_telegram',
    ];

    $integrationKeys = collect($integrationHealth)->pluck('key')->all();

    foreach ($integrationHealth as $card) {
        $key = (string) ($card['key'] ?? '');
        if (in_array($key, ['cashfree', 'telegram'], true)) {
            continue;
        }

        $status = (string) ($card['status'] ?? 'healthy');

        $systems[] = [
            'id' => 'integration-'.$key,
            'label' => (string) ($card['label'] ?? 'Integration'),
            'healthy' => $status === 'healthy',
            'detail' => $status === 'healthy' ? null : (string) ($card['detail'] ?? 'Needs attention'),
            'status_class' => $statusClassMap[$status] ?? 'info',
            'status_label' => (string) ($card['status_label'] ?? 'Unknown'),
            'lazy_section' => null,
        ];
    }

    $issueSystems = collect($systems)->filter(fn (array $system): bool => ! $system['healthy'])->values()->all();
    $healthySystems = collect($systems)->filter(fn (array $system): bool => $system['healthy'])->values()->all();
    $allHealthy = $issueSystems === [];
@endphp

<section class="operations-health-compact" aria-labelledby="operations-health-compact-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <h2 id="operations-health-compact-heading" class="h6 mb-0 text-uppercase text-muted fw-semibold">Integration Health</h2>
            <span class="visually-hidden">System Health</span>
        </div>
        @if ($allHealthy)
            <span class="status-badge status-healthy">All healthy</span>
        @else
            <span class="status-badge status-warning">{{ number_format(count($issueSystems)) }} need attention</span>
        @endif
    </div>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body py-3">
            <div class="operations-integration-grid" role="list" aria-label="Integration status">
                @foreach ($systems as $system)
                    <div
                        @class([
                            'operations-integration-pill',
                            'operations-integration-pill--' . ($system['status_class'] ?? 'info'),
                            'operations-integration-pill--issue' => ! $system['healthy'],
                        ])
                        role="listitem"
                    >
                        <span class="operations-integration-pill-icon" aria-hidden="true">{{ $system['healthy'] ? '✓' : '!' }}</span>
                        <span class="operations-integration-pill-label">{{ $system['label'] }}</span>
                    </div>
                @endforeach
            </div>

            @if ($issueSystems !== [])
                <div class="operations-health-accordion accordion mt-3" id="operations-health-accordion">
                    @foreach ($issueSystems as $system)
                        @if ($system['lazy_section'] === null)
                            <div class="operations-health-issue-detail small text-muted py-1">
                                <strong>{{ $system['label'] }}:</strong> {{ $system['detail'] }}
                            </div>
                        @else
                            @php
                                $collapseId = 'operations-health-'.$system['id'];
                            @endphp
                            <div class="accordion-item border-0 shadow-sm mb-2 operations-card-hover">
                                <h3 class="accordion-header" id="operations-health-heading-{{ $system['id'] }}">
                                    <button
                                        class="accordion-button operations-health-accordion-button collapsed py-2"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#{{ $collapseId }}"
                                        aria-expanded="false"
                                        aria-controls="{{ $collapseId }}"
                                        id="operations-health-trigger-{{ $system['id'] }}"
                                    >
                                        <span class="operations-health-accordion-title">{{ $system['label'] }}</span>
                                        <span class="operations-health-accordion-summary text-muted">{{ $system['detail'] }}</span>
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
                        @endif
                    @endforeach
                </div>
            @else
                <p class="text-muted small mb-0 mt-2 operations-health-empty-state">✓ All systems operational</p>
            @endif
        </div>
    </div>
</section>
