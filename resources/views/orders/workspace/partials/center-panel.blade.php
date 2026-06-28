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
    $slaLabel = $repairIncident ? $repairIncident->slaStatus()->label() : '—';
    $priorityLabel = $repairIncident?->high_priority ? 'High' : ($repairIncident ? 'Normal' : '—');
    $primaryStatus = $repairIncident?->status->label() ?? ($order->isTransactionLocked() ? 'Complete' : 'Awaiting payment');

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
    <div class="order-workspace-sticky-bar" data-workspace-sticky-bar hidden aria-hidden="true">
        <div class="order-workspace-sticky-bar-inner">
            <div class="order-workspace-sticky-identity">
                <span class="order-workspace-sticky-order-id">{{ $order->order_id }}</span>
                <span class="order-workspace-sticky-customer">{{ $order->customer_name ?: '—' }}</span>
                <span class="order-workspace-sticky-status">{{ $primaryStatus }}</span>
            </div>
            <div class="order-workspace-sticky-actions">
                <button type="button" class="order-workspace-sticky-btn" disabled title="Coming soon">
                    <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                    <span class="visually-hidden">Call</span>
                </button>
                <button type="button" class="order-workspace-sticky-btn" disabled title="Coming soon">
                    <i class="bi bi-whatsapp" aria-hidden="true"></i>
                    <span class="visually-hidden">WhatsApp</span>
                </button>
                <button type="button" class="order-workspace-sticky-btn" disabled title="Coming soon">
                    <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                    <span class="visually-hidden">Email</span>
                </button>
                @can('create', App\Models\Incident::class)
                    <a href="{{ route('orders.service-cases.create', $order) }}"
                       class="order-workspace-sticky-btn order-workspace-sticky-btn--primary"
                       title="Create Ticket">
                        <i class="bi bi-ticket-detailed-fill" aria-hidden="true"></i>
                        <span class="visually-hidden">Create Ticket</span>
                    </a>
                @endcan
            </div>
        </div>
    </div>

    <header class="order-workspace-header" data-workspace-header>
        <nav aria-label="breadcrumb" class="order-workspace-breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $order->order_id }}</li>
            </ol>
        </nav>

        <div class="order-workspace-header-hero">
            <div class="order-workspace-header-identity">
                <h1 class="order-workspace-order-id">{{ $order->order_id }}</h1>
                <p class="order-workspace-customer-name">
                    @if($order->customer_phone)
                        <a href="tel:{{ $order->customer_phone }}" class="order-workspace-customer-link">
                            {{ $order->customer_name ?: '—' }}
                        </a>
                    @else
                        {{ $order->customer_name ?: '—' }}
                    @endif
                </p>
            </div>

            @include('orders.workspace.partials.status-chips', [
                'order' => $order,
                'activeIncident' => $activeIncident,
            ])
        </div>

        <div class="order-workspace-header-actions">
            @include('orders.workspace.partials.quick-actions', ['order' => $order])
        </div>

        <dl class="order-workspace-header-essentials">
            <div>
                <dt>Owner</dt>
                <dd>{{ $ownerName }}</dd>
            </div>
            <div>
                <dt>Engineer</dt>
                <dd>{{ $engineerName }}</dd>
            </div>
            <div>
                <dt>SLA</dt>
                <dd>{{ $slaLabel }}</dd>
            </div>
            <div>
                <dt>Priority</dt>
                <dd>{{ $priorityLabel }}</dd>
            </div>
        </dl>

        <details class="order-workspace-header-details">
            <summary class="order-workspace-header-details-toggle">
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                Order details
            </summary>
            <dl class="order-workspace-header-meta">
                <div>
                    <dt>Repair ID</dt>
                    <dd>{{ $repairId }}</dd>
                </div>
                <div>
                    <dt>Created</dt>
                    <dd>{{ display_app_datetime_24($order->created_at) }}</dd>
                </div>
                <div>
                    <dt>Last Updated</dt>
                    <dd>{{ display_app_datetime_24($order->updated_at) }}</dd>
                </div>
            </dl>
        </details>
    </header>

    <div class="order-workspace-sticky-sentinel" data-workspace-sticky-sentinel aria-hidden="true"></div>

    @if($order->isTransactionLocked())
        <div class="alert alert-success order-workspace-alert py-2 small mb-0">
            <i class="bi bi-lock-fill me-1"></i>
            This order is completed. Transaction ID <strong>{{ $order->transaction_id }}</strong>
            was saved on {{ display_app_datetime($order->completed_at) }}.
        </div>
    @endif

    @include('orders.workspace.partials.repair-workflow', [
        'order' => $order,
        'activeIncident' => $activeIncident,
    ])

    @include('orders.workspace.partials.active-service-case-card', [
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
