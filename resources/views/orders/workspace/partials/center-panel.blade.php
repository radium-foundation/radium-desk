@props([
    'order',
    'activeIncident' => null,
    'activityTimeline',
    'timelineRemarks',
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
    $primaryStatus = $repairIncident?->status->label() ?? ($order->isTransactionLocked() ? 'Complete' : 'Awaiting activation');

    $tabs = [
        'overview' => ['label' => 'Overview', 'icon' => 'bi-grid'],
        'timeline' => ['label' => 'Timeline', 'icon' => 'bi-clock-history'],
        'payments' => ['label' => 'Payments', 'icon' => 'bi-credit-card'],
        'device' => ['label' => 'Device', 'icon' => 'bi-phone'],
        'communication' => ['label' => 'Communication', 'icon' => 'bi-chat-dots'],
        'notes' => ['label' => 'Notes', 'icon' => 'bi-journal-text'],
    ];
@endphp

<div class="order-workspace-center">
    <div class="order-workspace-sticky-bar" data-workspace-sticky-bar hidden aria-hidden="true">
        <div class="order-workspace-sticky-bar-inner">
            <div class="order-workspace-sticky-identity">
                <span class="order-workspace-sticky-order-id">
                    <x-order-identifier :order="$order" class="order-workspace-order-id" />
                </span>
                <span class="order-workspace-sticky-status">{{ $primaryStatus }}</span>
            </div>
            <div class="order-workspace-sticky-actions">
                @if($order->customer_phone)
                    <a href="tel:{{ $order->customer_phone }}"
                       class="order-workspace-sticky-btn"
                       title="Call customer">
                        <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                        <span class="visually-hidden">Call</span>
                    </a>
                @endif
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
                <li class="breadcrumb-item active" aria-current="page">
                    <x-order-identifier :order="$order" />
                </li>
            </ol>
        </nav>

        <div class="order-workspace-header-hero">
            <div class="order-workspace-header-identity">
                <h1 class="order-workspace-order-id">
                    <x-order-identifier :order="$order" />
                </h1>
            </div>

            @include('orders.workspace.partials.status-chips', [
                'order' => $order,
                'activeIncident' => $activeIncident,
            ])
        </div>

        <div class="order-workspace-header-actions">
            @include('orders.workspace.partials.quick-actions', ['order' => $order])
        </div>
    </header>

    <div class="order-workspace-sticky-sentinel" data-workspace-sticky-sentinel aria-hidden="true"></div>

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
