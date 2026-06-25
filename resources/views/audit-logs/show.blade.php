@extends('layouts.app')

@section('title', 'Audit Log #'.$auditLog->id)

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('audit-logs.index') }}">Audit Logs</a></li>
                <li class="breadcrumb-item active" aria-current="page">Entry #{{ $auditLog->id }}</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Audit Log Detail</h1>
        <p class="text-muted mb-0">
            @include('audit-logs.partials.event-badge', ['auditLog' => $auditLog])
            <span class="ms-2">{{ class_basename($auditLog->auditable_type) }} #{{ $auditLog->auditable_id ?: '—' }}</span>
        </p>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Entry Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">User</dt>
                        <dd class="col-sm-8">{{ $auditLog->user?->name ?? 'System' }}</dd>

                        <dt class="col-sm-4 text-muted">Event</dt>
                        <dd class="col-sm-8">@include('audit-logs.partials.event-badge', ['auditLog' => $auditLog])</dd>

                        <dt class="col-sm-4 text-muted">Timestamp</dt>
                        <dd class="col-sm-8">{{ display_app_datetime_seconds($auditLog->created_at) }}</dd>

                        <dt class="col-sm-4 text-muted">Module</dt>
                        <dd class="col-sm-8">{{ class_basename($auditLog->auditable_type) }}</dd>

                        <dt class="col-sm-4 text-muted">Record ID</dt>
                        <dd class="col-sm-8">
                            @if($auditLog->auditable_id)
                                <span class="font-monospace">#{{ $auditLog->auditable_id }}</span>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted">IP Address</dt>
                        <dd class="col-sm-8 font-monospace">{{ $auditLog->ip_address ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">User Agent</dt>
                        <dd class="col-sm-8"><small class="text-break">{{ $auditLog->user_agent ?: '—' }}</small></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Old Values</h2>
                </div>
                <div class="card-body">
                    @if(empty($auditLog->old_values))
                        <p class="text-muted mb-0">No old values recorded.</p>
                    @else
                        <pre class="bg-light border rounded p-3 mb-0 small overflow-auto"><code>{{ json_encode($auditLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">New Values</h2>
                </div>
                <div class="card-body">
                    @if(empty($auditLog->new_values))
                        <p class="text-muted mb-0">No new values recorded.</p>
                    @else
                        <pre class="bg-light border rounded p-3 mb-0 small overflow-auto"><code>{{ json_encode($auditLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
