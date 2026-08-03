@extends('layouts.app')

@section('title', $template->name)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ $template->name }}</h1>
            <p class="text-muted mb-0">
                <code>{{ $template->key }}</code>
                · {{ $template->category->label() }}
                · {{ $template->channelLabels() }}
                · {{ $template->status->label() }}
                · Current v{{ $template->current_version }}
                · Approved v{{ $template->approved_version ?: '—' }}
                · Runtime: {{ $template->runtimeLabel() }}
                · Health: {{ strtoupper($template->runtimeHealth()) }}
                · Fallbacks: {{ $template->fallback_count }}
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.communication-templates.compare', $template) }}" class="btn btn-outline-secondary">Compare</a>
            @if($canManage)
                <a href="{{ route('admin.communication-templates.edit', $template) }}" class="btn btn-primary">Edit (draft)</a>
                @if($template->status->value !== 'approved')
                    <form method="POST" action="{{ route('admin.communication-templates.approve', $template) }}">@csrf
                        <button class="btn btn-outline-success" type="submit">Approve</button>
                    </form>
                @endif
                @if($template->status->value !== 'deprecated')
                    <form method="POST" action="{{ route('admin.communication-templates.deprecate', $template) }}">@csrf
                        <button class="btn btn-outline-warning" type="submit">Deprecate</button>
                    </form>
                @endif
            @endif
            <a href="{{ route('admin.communication-templates.index') }}" class="btn btn-outline-secondary">Back</a>
        </div>
    </div>

    @include('navigation.administration-workspace-nav', ['active' => 'communication'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Preview (sample data)</h2></div>
                <div class="card-body">
                    @if($preview['subject'])
                        <div class="fw-semibold mb-2">{{ $preview['subject'] }}</div>
                    @endif
                    <div class="border rounded p-3">{!! $preview['html'] !!}</div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Blade vs Store comparison</h2>
                    <span class="badge {{ ($comparison['identical'] ?? false) ? 'text-bg-success' : 'text-bg-warning' }}">
                        {{ ($comparison['identical'] ?? false) ? 'Identical' : 'Diff '.round(($comparison['diff_ratio'] ?? 0) * 100).'%' }}
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted mb-1">Blade</div>
                            <div class="border rounded p-2 small" style="max-height: 240px; overflow: auto;">{!! $comparison['blade_html'] !!}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted mb-1">Store</div>
                            <div class="border rounded p-2 small" style="max-height: 240px; overflow: auto;">{!! $comparison['store_html'] !!}</div>
                        </div>
                    </div>
                </div>
            </div>

            @if($canManage)
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><h2 class="h6 mb-0">Test Send (Super Admin)</h2></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.communication-templates.test-send', $template) }}" class="row g-3">
                            @csrf
                            <div class="col-md-6">
                                <label class="form-label" for="recipient_email">Recipient</label>
                                <input type="email" name="recipient_email" id="recipient_email" class="form-control" required
                                       value="{{ old('recipient_email', auth()->user()?->email) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="sample_order_id">Sample Order ID (optional)</label>
                                <input type="text" name="sample_order_id" id="sample_order_id" class="form-control"
                                       value="{{ old('sample_order_id') }}" placeholder="RD3450001">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-outline-primary">Send test email</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Usage</h2></div>
                <div class="card-body p-0">
                    <div class="p-3 small text-muted">
                        Used {{ $template->usage_count }} time(s)
                        @if($template->last_used_at)
                            · last {{ $template->last_used_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
                        @endif
                        @if($template->last_send_at)
                            · last send {{ $template->last_send_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>By</th>
                                    <th>Channel</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($template->usages as $usage)
                                    <tr>
                                        <td>{{ $usage->used_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                                        <td>{{ $usage->user?->name ?? '—' }}</td>
                                        <td>{{ $usage->channel }}</td>
                                        <td>{{ $usage->communication_type ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center py-3">No usage recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Versions</h2></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Version</th>
                                    <th>By</th>
                                    <th>Date</th>
                                    <th>Reason</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($template->versions as $version)
                                    <tr @class([
                                        'table-primary' => $version->version === $template->current_version,
                                        'table-success' => $version->version === $template->approved_version,
                                    ])>
                                        <td>
                                            v{{ $version->version }}
                                            @if($version->version === $template->approved_version)
                                                <span class="badge text-bg-success">Approved</span>
                                            @endif
                                        </td>
                                        <td>{{ $version->creator?->name ?? '—' }}</td>
                                        <td>{{ $version->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                                        <td class="small">{{ $version->change_reason }}</td>
                                        <td>
                                            @if($canManage && $version->version !== $template->current_version)
                                                <form method="POST" action="{{ route('admin.communication-templates.rollback', $template) }}">
                                                    @csrf
                                                    <input type="hidden" name="version" value="{{ $version->version }}">
                                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Rollback</button>
                                                </form>
                                            @elseif($version->version === $template->current_version)
                                                <span class="badge text-bg-primary">Current</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Runtime</h2></div>
                <div class="card-body small">
                    <div class="mb-2">
                        Preferred: <strong>{{ $template->runtimeLabel() }}</strong>
                        · Health: <strong>{{ strtoupper($template->runtimeHealth()) }}</strong>
                    </div>
                    @if($template->blade_view)
                        <div class="text-muted">Blade fallback: <code>{{ $template->blade_view }}</code></div>
                    @endif
                    @if($template->last_error)
                        <div class="text-danger mt-2">Last error: {{ $template->last_error }}</div>
                    @endif
                    <div class="text-muted mt-2">Blade files are never removed. Fallback remains until zero production fallbacks for a stable release.</div>
                </div>
            </div>
        </div>
    </div>
@endsection
