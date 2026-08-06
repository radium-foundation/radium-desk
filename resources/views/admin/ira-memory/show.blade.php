@extends('layouts.app')

@section('title', 'IRA Memory #'.$memory->id)

@section('content')
    @php
        $detail = $detail ?? [];
        $explain = $detail['explain'] ?? [];
    @endphp

    <div class="ira-memory-page" data-ira-memory-admin>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
            <div>
                <div class="mb-2">
                    <a href="{{ route('admin.ira-memory.index') }}" class="text-decoration-none small">
                        ← Back to IRA Memory
                    </a>
                </div>
                <h1 class="h3 mb-1">{{ $detail['pattern_kind_label'] ?? 'Pattern' }} · {{ $detail['pattern_value'] ?? '' }}</h1>
                <p class="text-muted mb-0">
                    {{ $detail['decision_label'] ?? '—' }}
                    · {{ $detail['status_label'] ?? '—' }}
                    · Confidence {{ $detail['confidence'] ?? 0 }}% ({{ $detail['confidence_band'] ?? '—' }})
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if($detail['can_toggle'] ?? false)
                    <form method="POST" action="{{ route('admin.ira-memory.toggle', $memory) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-outline-secondary">
                            {{ ($detail['is_active'] ?? false) ? 'Disable' : 'Enable' }}
                        </button>
                    </form>
                @endif
                @if($detail['can_edit'] ?? false)
                    <form method="POST"
                          action="{{ route('admin.ira-memory.destroy', $memory) }}"
                          onsubmit="return confirm('Soft-delete this memory? Usage count: {{ (int) ($detail['times_used'] ?? 0) }}. It can be reviewed under Deleted.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">Delete</button>
                    </form>
                @endif
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

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Overview</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 ira-memory-dl">
                            <dt class="col-sm-4">Pattern</dt>
                            <dd class="col-sm-8">{{ $detail['pattern_kind_label'] }} · <code>{{ $detail['pattern_value'] }}</code></dd>

                            <dt class="col-sm-4">Decision</dt>
                            <dd class="col-sm-8">
                                {{ $detail['decision_label'] }}
                                <div class="small text-muted">
                                    {{ $detail['memory_type_label'] }} / {{ $detail['decision_kind_label'] }}
                                    · value <code>{{ $detail['decision_value'] }}</code>
                                </div>
                            </dd>

                            <dt class="col-sm-4">Source</dt>
                            <dd class="col-sm-8">{{ $detail['source_label'] }}</dd>

                            <dt class="col-sm-4">Created from</dt>
                            <dd class="col-sm-8">
                                {{ $detail['created_from_label'] }}
                                <div class="small text-muted">{{ $detail['created_by'] }}
                                    @if(! empty($detail['created_by_email']))
                                        · {{ $detail['created_by_email'] }}
                                    @endif
                                </div>
                            </dd>

                            <dt class="col-sm-4">Created</dt>
                            <dd class="col-sm-8">{{ $detail['created_at_label'] ?? '—' }}</dd>

                            <dt class="col-sm-4">Last used</dt>
                            <dd class="col-sm-8">{{ $detail['last_used_label'] ?? '—' }}</dd>

                            <dt class="col-sm-4">Times used</dt>
                            <dd class="col-sm-8">{{ number_format((int) ($detail['times_used'] ?? 0)) }}</dd>

                            <dt class="col-sm-4">Confidence</dt>
                            <dd class="col-sm-8">
                                <span class="ira-memory-confidence ira-memory-confidence--{{ strtolower($detail['confidence_band'] ?? 'low') }}">
                                    {{ $detail['confidence'] ?? 0 }}%
                                </span>
                                <span class="text-muted small">({{ $detail['confidence_band'] ?? '—' }})</span>
                            </dd>

                            <dt class="col-sm-4">Status</dt>
                            <dd class="col-sm-8">{{ $detail['status_label'] }}</dd>

                            <dt class="col-sm-4">Reason</dt>
                            <dd class="col-sm-8">{{ filled($detail['reason'] ?? null) ? $detail['reason'] : '—' }}</dd>

                            @if(! empty($detail['merged_into']))
                                <dt class="col-sm-4">Merged into</dt>
                                <dd class="col-sm-8">
                                    <a href="{{ $detail['merged_into']['url'] }}">{{ $detail['merged_into']['label'] }}</a>
                                </dd>
                            @endif
                        </dl>
                    </div>
                </div>

                @if($detail['can_edit'] ?? false)
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h2 class="h6 mb-0">Edit</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.ira-memory.update', $memory) }}" class="row g-3">
                                @csrf
                                @method('PUT')

                                <div class="col-md-4">
                                    <label for="pattern_kind" class="form-label">Pattern kind</label>
                                    <select name="pattern_kind" id="pattern_kind" class="form-select" required>
                                        @foreach($filterOptions['pattern_kinds'] ?? [] as $kind)
                                            <option value="{{ $kind->value }}" @selected(($detail['pattern_kind'] ?? '') === $kind->value)>
                                                {{ $kind->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label for="pattern_value" class="form-label">Pattern value</label>
                                    <input type="text" name="pattern_value" id="pattern_value" class="form-control"
                                           value="{{ old('pattern_value', $detail['pattern_value'] ?? '') }}" required>
                                    <div class="form-text">Changing the pattern may change what future mail matches.</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="decision_kind" class="form-label">Decision kind</label>
                                    <select name="decision_kind" id="decision_kind" class="form-select" required data-ira-memory-decision-kind>
                                        @foreach($filterOptions['decision_kinds'] ?? [] as $kind)
                                            <option value="{{ $kind->value }}" @selected(old('decision_kind', $detail['decision_kind'] ?? '') === $kind->value)>
                                                {{ $kind->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Decision value</label>
                                    <div data-ira-memory-decision-panels>
                                        <select class="form-select mb-2" data-ira-memory-decision-panel="assign"
                                                @disabled(old('decision_kind', $detail['decision_kind'] ?? '') !== 'assign')>
                                            @foreach($assignableUsers as $user)
                                                <option value="{{ $user->id }}"
                                                    @selected((string) old('decision_value', $detail['decision_value'] ?? '') === (string) $user->id)>
                                                    {{ $user->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <select class="form-select mb-2" data-ira-memory-decision-panel="classification"
                                                @disabled(old('decision_kind', $detail['decision_kind'] ?? '') !== 'classification')>
                                            @foreach($classificationOptions as $option)
                                                <option value="{{ $option->value }}"
                                                    @selected(old('decision_value', $detail['decision_value'] ?? '') === $option->value)>
                                                    {{ $option->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <select class="form-select mb-2" data-ira-memory-decision-panel="importance"
                                                @disabled(old('decision_kind', $detail['decision_kind'] ?? '') !== 'importance')>
                                            @foreach($importanceOptions as $option)
                                                <option value="{{ $option->value }}"
                                                    @selected(old('decision_value', $detail['decision_value'] ?? '') === $option->value)>
                                                    {{ $option->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <select class="form-select mb-2" data-ira-memory-decision-panel="ignore"
                                                @disabled(old('decision_kind', $detail['decision_kind'] ?? '') !== 'ignore')>
                                            @foreach($ignoreActions as $option)
                                                <option value="{{ $option->value }}"
                                                    @selected(old('decision_value', $detail['decision_value'] ?? '') === $option->value)>
                                                    {{ $option->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <input type="text" class="form-control mb-2" data-ira-memory-decision-panel="disposition"
                                               value="{{ old('decision_value', $detail['decision_value'] ?? '') }}"
                                               @disabled(old('decision_kind', $detail['decision_kind'] ?? '') !== 'disposition')>
                                    </div>
                                    <input type="hidden" name="decision_value" id="decision_value"
                                           value="{{ old('decision_value', $detail['decision_value'] ?? '') }}"
                                           data-ira-memory-decision-value>
                                </div>

                                <div class="col-md-4">
                                    <label for="memory_type" class="form-label">Memory type</label>
                                    <select name="memory_type" id="memory_type" class="form-select">
                                        @foreach($filterOptions['memory_types'] ?? [] as $type)
                                            <option value="{{ $type->value }}" @selected(old('memory_type', $detail['memory_type'] ?? '') === $type->value)>
                                                {{ $type->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="confidence" class="form-label">Confidence (1–100)</label>
                                    <input type="number" name="confidence" id="confidence" class="form-control"
                                           min="1" max="100"
                                           value="{{ old('confidence', $detail['confidence'] ?? 80) }}" required>
                                </div>
                                <div class="col-12">
                                    <label for="reason" class="form-label">Reason</label>
                                    <textarea name="reason" id="reason" class="form-control" rows="3"
                                              placeholder="Why this memory exists">{{ old('reason', $detail['reason'] ?? '') }}</textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary"
                                            onclick="return confirm('Save changes to this memory? Pattern edits affect future matching.');">
                                        Save changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-4 ira-mem-detail" aria-labelledby="ira-mem-explain-heading">
                    <div class="card-header bg-white py-3">
                        <h2 id="ira-mem-explain-heading" class="h6 mb-0">Explainability</h2>
                    </div>
                    <div class="card-body">
                        <dl class="ira-mem-explain-grid mb-0">
                            <div class="ira-mem-explain-item ira-mem-explain-item--wide">
                                <dt>Why matched</dt>
                                <dd>{{ $explain['why'] ?? '—' }}</dd>
                            </div>
                            <div class="ira-mem-explain-item">
                                <dt>Matched fields</dt>
                                <dd>{{ $explain['matched_fields'] ?? ($explain['pattern'] ?? '—') }}</dd>
                            </div>
                            <div class="ira-mem-explain-item">
                                <dt>Confidence</dt>
                                <dd>
                                    <span class="ira-lc-conf {{ $explain['confidence_band_class'] ?? ('ira-lc-conf--'.strtolower($detail['confidence_band'] ?? 'low')) }}">
                                        {{ $explain['confidence_band'] ?? ($detail['confidence_band'] ?? '—') }}
                                        · {{ $explain['confidence'] ?? ($detail['confidence'] ?? 0) }}%
                                    </span>
                                </dd>
                            </div>
                            <div class="ira-mem-explain-item">
                                <dt>Pattern</dt>
                                <dd>{{ $explain['pattern'] ?? (($detail['pattern_kind_label'] ?? 'Pattern').' · '.($detail['pattern_value'] ?? '')) }}</dd>
                            </div>
                            <div class="ira-mem-explain-item">
                                <dt>Rule source</dt>
                                <dd>{{ $explain['rule_source'] ?? trim(($detail['created_from_label'] ?? '—').' · '.($detail['source_label'] ?? '—'), ' ·') }}</dd>
                            </div>
                            <div class="ira-mem-explain-item">
                                <dt>Usage</dt>
                                <dd>{{ number_format((int) ($explain['usage'] ?? $detail['times_used'] ?? 0)) }}×</dd>
                            </div>
                            <div class="ira-mem-explain-item">
                                <dt>Last matched</dt>
                                <dd>{{ $explain['last_matched_label'] ?? ($detail['last_used_label'] ?? 'Never') }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">Example emails</h2>
                        <span class="text-muted small">{{ count($detail['example_emails'] ?? []) }} shown</span>
                    </div>
                    <div class="card-body p-0">
                        @if(($detail['example_emails'] ?? []) === [])
                            <p class="text-muted small mb-0 p-3">No derived email matches yet.</p>
                        @else
                            <ul class="list-group list-group-flush ira-mem-examples">
                                @foreach($detail['example_emails'] as $email)
                                    <li class="list-group-item">
                                        <div class="d-flex flex-wrap justify-content-between gap-2">
                                            <div class="fw-semibold">{{ $email['from_email'] }}</div>
                                            <div class="text-muted small text-nowrap">
                                                {{ $email['received_label'] }}
                                                @if($email['origin'])
                                                    <span class="badge text-bg-light border ms-1">Origin</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="mt-1">{{ $email['subject'] }}</div>
                                        @if(($email['preview'] ?? '') !== '')
                                            <div class="text-muted small mt-1">{{ $email['preview'] }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Linked rules</h2>
                    </div>
                    <div class="card-body">
                        @if(($detail['linked_rules'] ?? []) === [])
                            <p class="text-muted small mb-0">No sibling memories for this pattern.</p>
                        @else
                            <ul class="list-unstyled mb-0">
                                @foreach($detail['linked_rules'] as $linked)
                                    <li class="mb-2">
                                        <a href="{{ $linked['url'] }}">{{ $linked['decision_label'] }}</a>
                                        <div class="small text-muted">
                                            {{ $linked['status_label'] }} · {{ $linked['confidence'] }}%
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Related memories</h2>
                    </div>
                    <div class="card-body">
                        @if(($detail['related_memories'] ?? []) === [])
                            <p class="text-muted small mb-0">No explicit relations.</p>
                        @else
                            <ul class="list-unstyled mb-0">
                                @foreach($detail['related_memories'] as $related)
                                    <li class="mb-2">
                                        <span class="badge text-bg-light">{{ $related['relation_label'] }}</span>
                                        @if(! empty($related['url']))
                                            <a href="{{ $related['url'] }}">{{ $related['label'] }}</a>
                                        @else
                                            {{ $related['label'] }}
                                        @endif
                                        <div class="small text-muted">{{ $related['status_label'] }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if(($detail['merge_sources'] ?? []) !== [])
                            <hr>
                            <h3 class="h6">Merged into this memory</h3>
                            <ul class="list-unstyled mb-0">
                                @foreach($detail['merge_sources'] as $source)
                                    <li class="mb-1">
                                        <a href="{{ $source['url'] }}">{{ $source['label'] }}</a>
                                        <span class="small text-muted">({{ number_format($source['times_used']) }} uses)</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @vite('resources/js/ira-memory-admin.js')
@endpush
