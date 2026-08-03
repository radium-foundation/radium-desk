@extends('layouts.app')

@section('title', 'Communication Health')

@section('content')
    @php($totals = $dashboard['totals'])
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Communication Health</h1>
            <p class="text-muted mb-0">Runtime verification for Template Store · Blade fallback safety</p>
        </div>
        <a href="{{ route('admin.communication-templates.index') }}" class="btn btn-outline-secondary">Template Store</a>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'communication'])

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Templates</div><div class="h4 mb-0">{{ $totals['templates'] }}</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Approved</div><div class="h4 mb-0">{{ $totals['approved'] }}</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Store Runtime</div><div class="h4 mb-0">{{ $totals['store_runtime'] }}</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Blade Runtime</div><div class="h4 mb-0">{{ $totals['blade_runtime'] }}</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Fallback Count</div><div class="h4 mb-0">{{ $totals['fallback_count'] }}</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Template Errors</div><div class="h4 mb-0">{{ $totals['template_errors'] }}</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Migration Progress</div><div class="h4 mb-0">{{ $totals['migration_progress'] }}%</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Avg Send (ms)</div><div class="h4 mb-0">{{ $dashboard['analytics']['avg_send_duration_ms'] ?: '—' }}</div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><h2 class="h6 mb-0">Templates</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Runtime</th>
                            <th>Fallback</th>
                            <th>Health</th>
                            <th>Usage</th>
                            <th>Last Send</th>
                            <th>Last Modified</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dashboard['rows'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.communication-templates.show', $row['id']) }}">{{ $row['name'] }}</a>
                                    <div class="small text-muted">{{ $row['notification_type'] }}</div>
                                </td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['runtime'] }}</td>
                                <td>{{ $row['fallback'] }} ({{ $row['fallback_count'] }})</td>
                                <td>{{ $row['health'] }}</td>
                                <td>{{ $row['usage_count'] }}</td>
                                <td>{{ $row['last_send_at'] ?? '—' }}</td>
                                <td>{{ $row['last_modified'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Most used</h2></div>
                <ul class="list-group list-group-flush">
                    @foreach($dashboard['analytics']['most_used'] as $template)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $template->name }}</span>
                            <span class="text-muted">{{ $template->usage_count }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Least used</h2></div>
                <ul class="list-group list-group-flush">
                    @foreach($dashboard['analytics']['least_used'] as $template)
                        <li class="list-group-item d-flex justify-content-between">
                            <span>{{ $template->name }}</span>
                            <span class="text-muted">{{ $template->usage_count }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endsection
