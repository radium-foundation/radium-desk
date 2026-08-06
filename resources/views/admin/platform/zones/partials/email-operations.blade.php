@props([
    'overview' => [],
    'zoneKey' => 'email_operations',
    'available' => false,
    'links' => [],
])

@php
    $enabled = (bool) ($overview['enabled'] ?? false);
    $kpis = is_array($overview['kpis'] ?? null) ? $overview['kpis'] : [];
    $pipeline = is_array($overview['pipeline'] ?? null) ? $overview['pipeline'] : [];
    $caseCreation = is_array($overview['case_creation'] ?? null) ? $overview['case_creation'] : [];
    $classification = is_array($overview['classification'] ?? null) ? $overview['classification'] : [];
    $assignment = is_array($overview['assignment'] ?? null) ? $overview['assignment'] : [];
    $iraMemory = is_array($overview['ira_memory'] ?? null) ? $overview['ira_memory'] : null;
    $exceptions = is_array($overview['exceptions'] ?? null) ? $overview['exceptions'] : [];
    $activity = is_array($overview['activity'] ?? null) ? $overview['activity'] : [];
@endphp

<div class="platform-email-operations" data-platform-email-operations="{{ $zoneKey }}">
    @if(! $enabled)
        <p class="text-muted small mb-0">
            Inbound email is disabled. Enable inbound email to monitor Email Operations here.
        </p>
    @elseif(! $available)
        <p class="text-muted small mb-0">Waiting for first background refresh.</p>
    @else
        <section class="platform-email-operations__section" data-platform-searchable="email operations today">
            <h3 class="h6 text-muted text-uppercase mb-2">Today’s Operations</h3>
            <div class="row g-2">
                @foreach($kpis as $kpi)
                    @php
                        $url = $kpi['url'] ?? null;
                        $highlight = (bool) ($kpi['highlight'] ?? false);
                    @endphp
                    <div class="col-6 col-md-4 col-xl-3">
                        @if($url)
                            <a href="{{ $url }}" class="card border-0 shadow-sm h-100 text-decoration-none platform-email-operations__kpi{{ $highlight ? ' platform-email-operations__kpi--alert' : '' }}">
                                <div class="card-body py-2 px-3">
                                    <div class="text-muted small">{{ $kpi['label'] ?? '' }}</div>
                                    <div class="fs-4 fw-semibold text-body lh-1">{{ number_format((int) ($kpi['count'] ?? 0)) }}</div>
                                </div>
                            </a>
                        @else
                            <div class="card border-0 shadow-sm h-100 platform-email-operations__kpi{{ $highlight ? ' platform-email-operations__kpi--alert' : '' }}">
                                <div class="card-body py-2 px-3">
                                    <div class="text-muted small">{{ $kpi['label'] ?? '' }}</div>
                                    <div class="fs-4 fw-semibold lh-1">{{ number_format((int) ($kpi['count'] ?? 0)) }}</div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        @if($pipeline !== [])
            <section class="platform-email-operations__section mt-3" data-platform-searchable="email processing pipeline">
                <h3 class="h6 text-muted text-uppercase mb-2">Processing Pipeline</h3>
                <div class="platform-email-operations__pipeline d-flex flex-wrap align-items-center gap-2">
                    @foreach($pipeline as $index => $step)
                        @if($index > 0)
                            <span class="text-muted small" aria-hidden="true">↓</span>
                        @endif
                        <span class="badge text-bg-light border platform-email-operations__pipe-step">
                            {{ $step['label'] ?? '' }}
                            <strong class="ms-1">{{ number_format((int) ($step['count'] ?? 0)) }}</strong>
                        </span>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="row g-3 mt-1">
            @if($caseCreation !== [])
                <div class="col-md-6" data-platform-searchable="email case creation">
                    <section class="platform-email-operations__section h-100">
                        <h3 class="h6 text-muted text-uppercase mb-2">Case Creation</h3>
                        <div class="list-group list-group-flush border rounded">
                            @foreach($caseCreation as $row)
                                @php $url = $row['url'] ?? null; @endphp
                                @if($url)
                                    <a href="{{ $url }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                                        <span>{{ $row['label'] ?? '' }}</span>
                                        <strong>{{ number_format((int) ($row['count'] ?? 0)) }}</strong>
                                    </a>
                                @else
                                    <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <span>{{ $row['label'] ?? '' }}</span>
                                        <strong>{{ number_format((int) ($row['count'] ?? 0)) }}</strong>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                </div>
            @endif

            @if($classification !== [])
                <div class="col-md-6" data-platform-searchable="email classification">
                    <section class="platform-email-operations__section h-100">
                        <h3 class="h6 text-muted text-uppercase mb-2">Classification</h3>
                        <div class="list-group list-group-flush border rounded">
                            @foreach($classification as $row)
                                @php $url = $row['url'] ?? null; @endphp
                                @if($url)
                                    <a href="{{ $url }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                                        <span>{{ $row['label'] ?? '' }}</span>
                                        <strong>{{ number_format((int) ($row['count'] ?? 0)) }}</strong>
                                    </a>
                                @else
                                    <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <span>{{ $row['label'] ?? '' }}</span>
                                        <strong>{{ number_format((int) ($row['count'] ?? 0)) }}</strong>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                </div>
            @endif
        </div>

        @if($assignment !== [])
            <section class="platform-email-operations__section mt-3" data-platform-searchable="email assignment health">
                <h3 class="h6 text-muted text-uppercase mb-2">Assignment Health</h3>
                <div class="row g-2">
                    @foreach($assignment as $row)
                        @php $url = $row['url'] ?? null; @endphp
                        <div class="col-6 col-md-3">
                            @if($url)
                                <a href="{{ $url }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                                    <div class="card-body py-2 px-3">
                                        <div class="text-muted small">{{ $row['label'] ?? '' }}</div>
                                        <div class="fs-5 fw-semibold text-body">{{ number_format((int) ($row['count'] ?? 0)) }}</div>
                                    </div>
                                </a>
                            @else
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body py-2 px-3">
                                        <div class="text-muted small">{{ $row['label'] ?? '' }}</div>
                                        <div class="fs-5 fw-semibold">{{ number_format((int) ($row['count'] ?? 0)) }}</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if($iraMemory !== null)
            <section class="platform-email-operations__section mt-3" data-platform-searchable="ira memory email">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 text-muted text-uppercase mb-0">IRA Memory</h3>
                    @if(! empty($iraMemory['url']))
                        <a href="{{ $iraMemory['url'] }}" class="small">Browse memories</a>
                    @endif
                </div>
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Used Today</div>
                                <div class="fs-5 fw-semibold">{{ number_format((int) ($iraMemory['used_today'] ?? 0)) }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">New Memories</div>
                                <div class="fs-5 fw-semibold">{{ number_format((int) ($iraMemory['new_memories'] ?? 0)) }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Top Memory</div>
                                <div class="small fw-semibold text-truncate" title="{{ $iraMemory['top_memory']['label'] ?? '—' }}">
                                    {{ $iraMemory['top_memory']['label'] ?? '—' }}
                                </div>
                                @if(! empty($iraMemory['top_memory']['times_used']))
                                    <div class="text-muted small">{{ number_format((int) $iraMemory['top_memory']['times_used']) }} uses</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Average Confidence</div>
                                <div class="fs-5 fw-semibold">
                                    @if($iraMemory['average_confidence'] !== null)
                                        {{ (int) $iraMemory['average_confidence'] }}%
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="platform-email-operations__section mt-3" data-platform-searchable="email exceptions">
            <h3 class="h6 text-muted text-uppercase mb-2">Exceptions</h3>
            @if($exceptions === [])
                <p class="text-muted small mb-0">No actionable email exceptions right now.</p>
            @else
                <div class="list-group list-group-flush border rounded">
                    @foreach($exceptions as $exception)
                        @php
                            $url = $exception['url'] ?? null;
                            $severity = (string) ($exception['severity'] ?? 'warning');
                            $badge = $severity === 'critical' ? 'danger' : 'warning';
                        @endphp
                        @if($url)
                            <a href="{{ $url }}" class="list-group-item list-group-item-action py-2">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <span class="badge text-bg-{{ $badge }} me-1">{{ $exception['label'] ?? '' }}</span>
                                        <span class="small text-muted">{{ $exception['detail'] ?? '' }}</span>
                                    </div>
                                    <span class="small text-nowrap">Open</span>
                                </div>
                            </a>
                        @else
                            <div class="list-group-item py-2">
                                <span class="badge text-bg-{{ $badge }} me-1">{{ $exception['label'] ?? '' }}</span>
                                <span class="small text-muted">{{ $exception['detail'] ?? '' }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        <section class="platform-email-operations__section mt-3" data-platform-searchable="email recent activity">
            <h3 class="h6 text-muted text-uppercase mb-2">Recent Activity</h3>
            @if($activity === [])
                <p class="text-muted small mb-0">No recent inbound email activity.</p>
            @else
                <div class="list-group list-group-flush border rounded">
                    @foreach($activity as $event)
                        @php
                            $url = $event['url'] ?? null;
                            $at = ! empty($event['at']) ? \Illuminate\Support\Carbon::parse($event['at']) : null;
                        @endphp
                        @if($url)
                            <a href="{{ $url }}" class="list-group-item list-group-item-action py-2">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <strong class="small">{{ $event['label'] ?? '' }}</strong>
                                        <div class="text-muted small">{{ $event['detail'] ?? '' }}</div>
                                    </div>
                                    <span class="text-muted small text-nowrap">
                                        {{ $at ? \App\Support\AppDateFormatter::format($at, 'g:i A') : '—' }}
                                    </span>
                                </div>
                            </a>
                        @else
                            <div class="list-group-item py-2">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <strong class="small">{{ $event['label'] ?? '' }}</strong>
                                        <div class="text-muted small">{{ $event['detail'] ?? '' }}</div>
                                    </div>
                                    <span class="text-muted small text-nowrap">
                                        {{ $at ? \App\Support\AppDateFormatter::format($at, 'g:i A') : '—' }}
                                    </span>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if(is_array($links) && $links !== [])
        <div class="d-flex flex-wrap gap-2 mt-3">
            @foreach($links as $link)
                <a href="{{ $link['url'] ?? '#' }}" class="btn btn-sm btn-outline-secondary">
                    {{ $link['label'] ?? 'Open' }}
                </a>
            @endforeach
        </div>
    @endif
</div>
