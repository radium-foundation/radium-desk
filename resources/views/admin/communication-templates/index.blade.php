@extends('layouts.app')

@section('title', 'Template Store')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Template Store</h1>
            <p class="text-muted mb-0">Administration → Communication → runtime source of truth. Blade is fallback only.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.communication-health.index') }}" class="btn btn-outline-secondary">Communication Health</a>
            @can('create', App\Models\CommunicationTemplate::class)
                <form method="POST" action="{{ route('admin.communication-templates.import-blade') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary"
                            onclick="return confirm('Import missing Blade notification templates into the store?')">
                        Import from Blade
                    </button>
                </form>
                <a href="{{ route('admin.communication-templates.create') }}" class="btn btn-primary">New Template</a>
            @endcan
        </div>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'communication'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3"><h2 class="h6 mb-0">Filters</h2></div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="q">Search</label>
                    <input type="search" name="q" id="q" class="form-control" value="{{ $filters['q'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="category">Category</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">All</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->value }}" @selected(($filters['category'] ?? '') === $category->value)>{{ $category->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="status">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="channel">Channel</label>
                    <select name="channel" id="channel" class="form-select">
                        <option value="">All</option>
                        @foreach($channels as $channel)
                            <option value="{{ $channel->value }}" @selected(($filters['channel'] ?? '') === $channel->value)>{{ $channel->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Apply</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.communication-templates.index') }}">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Channel</th>
                            <th>Status</th>
                            <th>Runtime</th>
                            <th>Health</th>
                            <th>Version</th>
                            <th>Usage</th>
                            <th>Last Modified</th>
                            <th>Modified By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($templates as $template)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.communication-templates.show', $template) }}">{{ $template->name }}</a>
                                    <div class="small text-muted">{{ $template->key }}</div>
                                </td>
                                <td>{{ $template->category->label() }}</td>
                                <td>{{ $template->channelLabels() }}</td>
                                <td><span class="badge text-bg-light">{{ $template->status->label() }}</span></td>
                                <td>
                                    @if(($template->last_runtime_source ?: $template->runtime_source) === 'store')
                                        <span class="text-success">Template Store</span>
                                    @else
                                        <span class="text-muted">Blade</span>
                                    @endif
                                </td>
                                <td>{{ strtoupper($template->runtimeHealth()) }}</td>
                                <td>v{{ $template->approved_version ?: $template->current_version }}</td>
                                <td>{{ $template->usage_count }}</td>
                                <td>{{ $template->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                                <td>{{ $template->updater?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No templates yet. Import from Blade to seed the store.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($templates->hasPages())
            <div class="card-footer bg-white">{{ $templates->links() }}</div>
        @endif
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Blade migration inventory</h2>
            <p class="small text-muted mb-0">Runtime still uses Blade. Store holds an editable mirror.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Notification Type</th>
                            <th>Category</th>
                            <th>Blade View</th>
                            <th>Exists</th>
                            <th>In Store</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($inventory as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['category'] }}</td>
                                <td><code>{{ $row['blade_view'] }}</code></td>
                                <td>{{ $row['blade_exists'] ? 'Yes' : 'No' }}</td>
                                <td>{{ $row['imported'] ? 'Yes' : 'No' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
