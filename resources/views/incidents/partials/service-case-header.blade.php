@props(['incident'])

<div class="card border-0 shadow-sm mb-3 service-case-header">
    <div class="card-body py-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h1 class="h3 mb-0">{{ $incident->display_reference }}</h1>
                    @include('incidents.partials.status-badge', ['status' => $incident->status])
                    @if($incident->high_priority)
                        @include('dashboard.partials.high-priority-badge')
                    @endif
                </div>
                <div class="d-flex flex-wrap gap-3 small text-muted">
                    <span>
                        <span class="text-muted">Assigned:</span>
                        <span class="fw-semibold text-body">{{ $incident->assignee?->firstName() ?: '—' }}</span>
                    </span>
                    <span>
                        <span class="text-muted">Created:</span>
                        <span class="text-body">{{ display_app_datetime_24($incident->created_at) }}</span>
                    </span>
                    <span>
                        <span class="text-muted">Last Updated:</span>
                        <span class="text-body">{{ display_app_datetime_24($incident->updated_at) }}</span>
                    </span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                @can('update', $incident)
                    <a href="{{ route('incidents.edit', $incident) }}"
                       class="btn btn-outline-primary btn-sm"
                       data-sc-action="edit">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                @endcan
                @if(auth()->user()->can('update', $incident) || auth()->user()->can('delete', $incident))
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                            More
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            @can('delete', $incident)
                                <li>
                                    <form method="POST" action="{{ route('incidents.destroy', $incident) }}"
                                          onsubmit="return confirm('{{ config('ui.service_case.delete_confirm') }}');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="dropdown-item text-danger">
                                            <i class="bi bi-trash me-2"></i> Delete
                                        </button>
                                    </form>
                                </li>
                            @endcan
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
