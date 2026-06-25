@if($activeIncident)
    <div class="alert alert-warning border-warning mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
            <div>
                <div class="fw-semibold mb-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    {{ config('ui.service_case.active_banner_heading') }}
                </div>
                <div class="fw-semibold">{{ $activeIncident->display_reference }}</div>
                <div class="small mt-1">
                    Assigned to {{ $activeIncident->assignee?->firstName() ?: '—' }}
                    · Status: {{ $activeIncident->status->label() }}
                    · Created {{ display_app_datetime($activeIncident->created_at) }}
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('incidents.show', $activeIncident) }}" class="btn btn-warning btn-sm">
                    {{ config('ui.service_case.open_existing_action') }}
                </a>
                @isset($continueUrl)
                    <a href="{{ $continueUrl }}" class="btn btn-outline-warning btn-sm">
                        {{ config('ui.service_case.continue_creating_action') }}
                    </a>
                @else
                    @can('create', App\Models\Incident::class)
                        <a href="{{ route('orders.service-cases.create', $order) }}" class="btn btn-outline-warning btn-sm">
                            {{ config('ui.service_case.continue_creating_action') }}
                        </a>
                    @endcan
                @endisset
            </div>
        </div>
    </div>
@endif
