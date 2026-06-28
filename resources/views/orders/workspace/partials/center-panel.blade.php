@props([
    'order',
    'activeIncident' => null,
    'activityTimeline',
    'timelineRemarks',
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
    $ownerName = $order->creator?->name ?: '—';
    $engineerName = $repairIncident?->assignee?->firstName() ?: '—';
    $repairId = $repairIncident?->display_reference ?? '—';

    $tabs = [
        'overview' => ['label' => 'Overview', 'icon' => 'bi-grid'],
        'timeline' => ['label' => 'Timeline', 'icon' => 'bi-clock-history'],
        'payments' => ['label' => 'Payments', 'icon' => 'bi-credit-card'],
        'device' => ['label' => 'Device', 'icon' => 'bi-phone'],
        'communication' => ['label' => 'Communication', 'icon' => 'bi-chat-dots'],
        'files' => ['label' => 'Files', 'icon' => 'bi-folder'],
        'notes' => ['label' => 'Notes', 'icon' => 'bi-journal-text'],
    ];
@endphp

<div class="order-workspace-center">
    <header class="order-workspace-header">
        <nav aria-label="breadcrumb" class="order-workspace-breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $order->order_id }}</li>
            </ol>
        </nav>

        <div class="order-workspace-header-main">
            <h1 class="order-workspace-order-id">{{ $order->order_id }}</h1>
            @include('orders.workspace.partials.status-chips', [
                'order' => $order,
                'activeIncident' => $activeIncident,
            ])
        </div>

        <dl class="order-workspace-header-meta">
            <div>
                <dt>Repair ID</dt>
                <dd>{{ $repairId }}</dd>
            </div>
            <div>
                <dt>Customer</dt>
                <dd>{{ $order->customer_name ?: '—' }}</dd>
            </div>
            <div>
                <dt>Owner</dt>
                <dd>{{ $ownerName }}</dd>
            </div>
            <div>
                <dt>Engineer</dt>
                <dd>{{ $engineerName }}</dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd>{{ display_app_datetime_24($order->created_at) }}</dd>
            </div>
            <div>
                <dt>Last Updated</dt>
                <dd>{{ display_app_datetime_24($order->updated_at) }}</dd>
            </div>
            <div>
                <dt>SLA</dt>
                <dd>{{ $repairIncident ? $repairIncident->slaStatus()->label() : '—' }}</dd>
            </div>
            <div>
                <dt>Priority</dt>
                <dd>{{ $repairIncident?->high_priority ? 'High' : ($repairIncident ? 'Normal' : '—') }}</dd>
            </div>
        </dl>
    </header>

    @include('orders.workspace.partials.quick-actions', ['order' => $order])

    @if($order->isTransactionLocked())
        <div class="alert alert-success order-workspace-alert py-2 small mb-0">
            <i class="bi bi-lock-fill me-1"></i>
            This order is completed. Transaction ID <strong>{{ $order->transaction_id }}</strong>
            was saved on {{ display_app_datetime($order->completed_at) }}.
        </div>
    @endif

    @include('orders.partials.active-service-case-banner', [
        'order' => $order,
        'activeIncident' => $activeIncident,
    ])

    <nav class="order-workspace-tabs" aria-label="Order workspace sections">
        <ul class="nav nav-pills order-workspace-tab-list" role="tablist">
            @foreach($tabs as $key => $tab)
                <li class="nav-item" role="presentation">
                    <button type="button"
                            @class(['nav-link', 'active' => $key === 'overview'])
                            role="tab"
                            aria-selected="{{ $key === 'overview' ? 'true' : 'false' }}"
                            aria-controls="order-workspace-tab-{{ $key }}"
                            data-workspace-tab="{{ $key }}">
                        <i class="bi {{ $tab['icon'] }}" aria-hidden="true"></i>
                        <span>{{ $tab['label'] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="order-workspace-tab-content">
        @foreach($tabs as $key => $tab)
            <div id="order-workspace-tab-{{ $key }}"
                 @class(['order-workspace-tab-pane', 'd-none' => $key !== 'overview'])
                 role="tabpanel"
                 data-workspace-tab-pane="{{ $key }}"
                 @if($key !== 'overview') data-lazy-tab @endif>
                @include('orders.workspace.partials.tabs.'.$key, [
                    'order' => $order,
                    'activeIncident' => $activeIncident,
                    'activityTimeline' => $activityTimeline,
                    'timelineRemarks' => $timelineRemarks,
                ])
            </div>
        @endforeach
    </div>
</div>
