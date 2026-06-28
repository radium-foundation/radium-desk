@props([
    'order',
    'activeIncident',
])

@if($activeIncident)
    <div class="order-workspace-service-case" role="region" aria-label="Active service case">
        <div class="order-workspace-service-case-header">
            <i class="bi bi-tools order-workspace-service-case-icon" aria-hidden="true"></i>
            <div>
                <h2 class="order-workspace-service-case-title">{{ config('ui.service_case.active_banner_heading') }}</h2>
                <p class="order-workspace-service-case-ref">{{ $activeIncident->display_reference }}</p>
            </div>
        </div>

        <dl class="order-workspace-service-case-meta">
            <div>
                <dt>Engineer</dt>
                <dd>{{ $activeIncident->assignee?->firstName() ?: 'Unassigned' }}</dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd>@include('incidents.partials.status-badge', ['status' => $activeIncident->status])</dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd>{{ display_app_datetime($activeIncident->created_at) }}</dd>
            </div>
        </dl>

        <div class="order-workspace-service-case-actions">
            <a href="{{ route('incidents.show', $activeIncident) }}" class="btn btn-sm btn-primary">
                {{ config('ui.service_case.open_existing_action') }}
            </a>
            @isset($continueUrl)
                <a href="{{ $continueUrl }}" class="btn btn-sm btn-outline-secondary">
                    {{ config('ui.service_case.continue_creating_action') }}
                </a>
            @else
                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-workspace-tab-trigger="notes">
                    <i class="bi bi-chat-left-text me-1"></i> Add Remark
                </button>
            @endisset
        </div>
    </div>
@endif
