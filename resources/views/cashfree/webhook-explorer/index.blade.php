@extends('layouts.app')

@section('title', 'Cashfree Webhook Explorer')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Webhook Explorer</h1>
        <p class="text-muted mb-0">Inspect incoming Cashfree webhook payloads captured from production.</p>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Search</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('cashfree.webhook-explorer.index') }}" class="row g-3">
                <div class="col-md-8 col-lg-9">
                    <label for="filter_q" class="form-label">Webhook ID</label>
                    <input type="search" name="q" id="filter_q" class="form-control"
                           value="{{ $filters['q'] ?? '' }}"
                           placeholder="Search by webhook log ID">
                </div>
                <div class="col-md-4 col-lg-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                    @if(! empty($filters['q']))
                        <a href="{{ route('cashfree.webhook-explorer.index') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($webhookLogs->isEmpty())
                <div class="p-4 text-center text-muted">
                    No webhook logs found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Received At</th>
                                <th>Processing Status</th>
                                <th>Source IP</th>
                                <th>User Agent</th>
                                <th>Event Type</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($webhookLogs as $webhookLog)
                                <tr>
                                    <td class="font-monospace">#{{ $webhookLog->id }}</td>
                                    <td class="text-nowrap">{{ display_app_datetime_seconds($webhookLog->received_at) }}</td>
                                    <td>@include('cashfree.webhook-explorer.partials.processing-status-badge', ['webhookLog' => $webhookLog])</td>
                                    <td class="font-monospace">{{ $webhookLog->source_ip ?: '—' }}</td>
                                    <td>
                                        <span class="text-muted small text-break">
                                            {{ $webhookLog->user_agent ? \Illuminate\Support\Str::limit($webhookLog->user_agent, 60) : '—' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($webhookLog->eventType())
                                            <span class="badge text-bg-light text-dark border">{{ $webhookLog->eventType() }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('cashfree.webhook-explorer.show', $webhookLog) }}" class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($webhookLogs->hasPages())
            <div class="card-footer bg-white">
                {{ $webhookLogs->links() }}
            </div>
        @endif
    </div>
@endsection
