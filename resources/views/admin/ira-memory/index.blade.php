@extends('layouts.app')

@section('title', 'IRA Memory')

@section('content')
    @php
        $filters = $filters ?? [];
        $filterOptions = $filterOptions ?? [];
        $testInput = $testInput ?? ['from_email' => '', 'subject' => '', 'preview' => '', 'mailbox' => ''];
        $testResult = $testResult ?? null;
    @endphp

    <div class="ira-memory-page" data-ira-memory-admin>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">IRA Memory</h1>
                <p class="text-muted mb-0">
                    Browse and manage durable business memory taught from Email Intake.
                    Teaching stays in the
                    <a href="{{ route('admin.incoming-emails.index') }}">Learning Center</a>.
                </p>
            </div>
        </div>

        @include('navigation.administration-workspace-nav', ['active' => 'ira_memory'])

        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Test Memory</h2>
                <span class="text-muted small">Dry-run match · no usage recorded</span>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.ira-memory.test') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4">
                        <label for="test_from_email" class="form-label">Sender</label>
                        <input type="text" name="from_email" id="test_from_email" class="form-control"
                               value="{{ $testInput['from_email'] }}" placeholder="vip@acme.com">
                    </div>
                    <div class="col-md-4">
                        <label for="test_subject" class="form-label">Subject</label>
                        <input type="text" name="subject" id="test_subject" class="form-control"
                               value="{{ $testInput['subject'] }}" placeholder="Urgent help with order 123">
                    </div>
                    <div class="col-md-4">
                        <label for="test_mailbox" class="form-label">Mailbox</label>
                        <input type="text" name="mailbox" id="test_mailbox" class="form-control"
                               value="{{ $testInput['mailbox'] }}" placeholder="support@radiumbox.com">
                    </div>
                    <div class="col-12">
                        <label for="test_preview" class="form-label">Preview / body snippet</label>
                        <textarea name="preview" id="test_preview" class="form-control" rows="2"
                                  placeholder="Optional keyword haystack">{{ $testInput['preview'] }}</textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-search me-1"></i> Show matching memory
                        </button>
                    </div>
                </form>

                @if($testResult !== null)
                    <div class="ira-memory-test-result mt-4">
                        <h3 class="h6 mb-2">
                            {{ (int) $testResult['count'] }} match{{ (int) $testResult['count'] === 1 ? '' : 'es' }}
                        </h3>
                        @if(($testResult['matches'] ?? []) === [])
                            <p class="text-muted small mb-0">No active memories match this probe.</p>
                        @else
                            <div class="list-group list-group-flush border rounded">
                                @foreach($testResult['matches'] as $match)
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                                            <div>
                                                <a href="{{ $match['url'] }}" class="fw-semibold text-decoration-none">
                                                    {{ $match['pattern_kind_label'] }} · {{ $match['pattern_value'] }}
                                                </a>
                                                <div class="small text-muted">
                                                    Matched on {{ $match['matched_on'] }}:
                                                    <code>{{ $match['matched_value'] }}</code>
                                                </div>
                                                <div class="small">{{ $match['decision_label'] }}</div>
                                            </div>
                                            <div class="text-end small">
                                                <span class="badge text-bg-{{ $match['is_active'] ? 'success' : 'secondary' }}">
                                                    {{ $match['status_label'] }}
                                                </span>
                                                <div class="mt-1">
                                                    Confidence {{ $match['confidence'] }}%
                                                    <span class="text-muted">({{ $match['confidence_band'] }})</span>
                                                </div>
                                                <div class="text-muted">Used {{ number_format($match['times_used']) }}×</div>
                                            </div>
                                        </div>
                                        @if(! empty($match['explainability']['why']))
                                            <p class="small text-muted mb-0 mt-2">{{ $match['explainability']['why'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h2 class="h6 mb-0">Search &amp; filters</h2>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.ira-memory.index') }}" class="row g-3">
                    <div class="col-md-4">
                        <label for="filter_q" class="form-label">Search</label>
                        <input type="search" name="q" id="filter_q" class="form-control"
                               value="{{ $filters['q'] ?? '' }}"
                               placeholder="Pattern, reason, decision, creator">
                    </div>
                    <div class="col-md-2">
                        <label for="filter_status" class="form-label">Status</label>
                        <select name="status" id="filter_status" class="form-select">
                            <option value="">All (not deleted)</option>
                            @foreach($filterOptions['statuses'] ?? [] as $status)
                                <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_memory_type" class="form-label">Type</label>
                        <select name="memory_type" id="filter_memory_type" class="form-select">
                            <option value="">All types</option>
                            @foreach($filterOptions['memory_types'] ?? [] as $type)
                                <option value="{{ $type->value }}" @selected(($filters['memory_type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_source" class="form-label">Source</label>
                        <select name="source" id="filter_source" class="form-select">
                            <option value="">All sources</option>
                            @foreach($filterOptions['sources'] ?? [] as $source)
                                <option value="{{ $source->value }}" @selected(($filters['source'] ?? '') === $source->value)>{{ $source->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_pattern_kind" class="form-label">Pattern</label>
                        <select name="pattern_kind" id="filter_pattern_kind" class="form-select">
                            <option value="">All patterns</option>
                            @foreach($filterOptions['pattern_kinds'] ?? [] as $kind)
                                <option value="{{ $kind->value }}" @selected(($filters['pattern_kind'] ?? '') === $kind->value)>{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_decision_kind" class="form-label">Decision</label>
                        <select name="decision_kind" id="filter_decision_kind" class="form-select">
                            <option value="">All decisions</option>
                            @foreach($filterOptions['decision_kinds'] ?? [] as $kind)
                                <option value="{{ $kind->value }}" @selected(($filters['decision_kind'] ?? '') === $kind->value)>{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_created_from" class="form-label">Created from</label>
                        <select name="created_from" id="filter_created_from" class="form-select">
                            <option value="">All origins</option>
                            @foreach($filterOptions['created_froms'] ?? [] as $origin)
                                <option value="{{ $origin->value }}" @selected(($filters['created_from'] ?? '') === $origin->value)>{{ $origin->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_confidence_band" class="form-label">Confidence</label>
                        <select name="confidence_band" id="filter_confidence_band" class="form-select">
                            <option value="">All bands</option>
                            @foreach($filterOptions['confidence_bands'] ?? [] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['confidence_band'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_has_usage" class="form-label">Usage</label>
                        <select name="has_usage" id="filter_has_usage" class="form-select">
                            <option value="">Any</option>
                            <option value="yes" @selected(($filters['has_usage'] ?? '') === 'yes')>Has usage</option>
                            <option value="no" @selected(($filters['has_usage'] ?? '') === 'no')>Never used</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_sort" class="form-label">Sort</label>
                        <select name="sort" id="filter_sort" class="form-select">
                            @foreach($filterOptions['sorts'] ?? [] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['sort'] ?? 'updated_at') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Apply</button>
                        <a href="{{ route('admin.ira-memory.index') }}" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0">Memories</h2>
                <form method="POST" action="{{ route('admin.ira-memory.merge') }}"
                      class="ira-memory-merge-bar d-flex flex-wrap align-items-center gap-2"
                      data-ira-memory-merge-bar hidden>
                    @csrf
                    <span class="small text-muted"><span data-ira-memory-selected-count>0</span> selected</span>
                    <label class="small mb-0" for="survivor_id">Survivor</label>
                    <select name="survivor_id" id="survivor_id" class="form-select form-select-sm" style="width: auto;" data-ira-memory-survivor>
                        <option value="">Choose survivor…</option>
                    </select>
                    <div data-ira-memory-source-inputs></div>
                    <button type="submit" class="btn btn-sm btn-warning"
                            onclick="return confirm('Merge selected memories into the survivor? Usage rolls up; sources become Merged.');">
                        Merge selected
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                @if(($rows ?? []) === [])
                    <div class="p-4 text-center text-muted">No memories match these filters.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 ira-memory-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 2.5rem;">
                                        <input type="checkbox" class="form-check-input" data-ira-memory-select-all aria-label="Select all">
                                    </th>
                                    <th>Pattern</th>
                                    <th>Type</th>
                                    <th>Decision</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                    <th>Confidence</th>
                                    <th>Used</th>
                                    <th>Last used</th>
                                    <th>Created from</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr>
                                        <td>
                                            @if(in_array($row['status'], ['active', 'disabled'], true))
                                                <input type="checkbox" class="form-check-input" value="{{ $row['id'] }}"
                                                       data-ira-memory-select
                                                       data-label="{{ $row['pattern_kind_label'] }} · {{ $row['pattern_value'] }}"
                                                       aria-label="Select memory {{ $row['id'] }}">
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ $row['url'] }}" class="fw-semibold text-decoration-none">{{ $row['pattern_value'] }}</a>
                                            <div class="small text-muted">{{ $row['pattern_kind_label'] }}</div>
                                        </td>
                                        <td>{{ $row['memory_type_label'] }}</td>
                                        <td>
                                            <div>{{ $row['decision_label'] }}</div>
                                            <div class="small text-muted">{{ $row['decision_kind_label'] }}</div>
                                        </td>
                                        <td>{{ $row['source_label'] }}</td>
                                        <td>
                                            @php
                                                $statusClass = match ($row['status']) {
                                                    'active' => 'success',
                                                    'disabled' => 'secondary',
                                                    'merged' => 'warning',
                                                    'deleted' => 'dark',
                                                    default => 'secondary',
                                                };
                                            @endphp
                                            <span class="badge text-bg-{{ $statusClass }}">{{ $row['status_label'] }}</span>
                                        </td>
                                        <td>
                                            <span class="ira-memory-confidence ira-memory-confidence--{{ strtolower($row['confidence_band']) }}">{{ $row['confidence'] }}%</span>
                                            <div class="small text-muted">{{ $row['confidence_band'] }}</div>
                                        </td>
                                        <td>{{ number_format($row['times_used']) }}</td>
                                        <td class="text-nowrap small">{{ $row['last_used_label'] }}</td>
                                        <td>
                                            <div>{{ $row['created_from_label'] }}</div>
                                            <div class="small text-muted">{{ $row['created_by'] }}</div>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                                            @if($row['can_toggle'])
                                                <form method="POST" action="{{ route('admin.ira-memory.toggle', $row['id']) }}" class="d-inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                            title="{{ $row['is_active'] ? 'Disable' : 'Enable' }}">
                                                        <i class="bi bi-{{ $row['is_active'] ? 'pause' : 'play' }}"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if(isset($memories) && $memories->hasPages())
                <div class="card-footer bg-white">{{ $memories->links() }}</div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    @vite('resources/js/ira-memory-admin.js')
@endpush
