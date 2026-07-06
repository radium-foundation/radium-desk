@php
    $latestIncident = $order->latestIncident();
    $assignedTeamMember = $activeIncident?->assignee?->firstName()
        ?? $latestIncident?->assignee?->firstName()
        ?? '—';
@endphp

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="row g-3 align-items-center">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h2 class="h5 mb-0">{{ $order->order_id }}</h2>
                    @include('orders.partials.completion-status-badge', ['order' => $order])
                </div>
                <div class="row g-2 small">
                    <div class="col-sm-6 col-xl-4">
                        <span class="text-muted">Customer</span>
                        <div class="fw-semibold">{{ $order->customer_name ?: '—' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-4">
                        <span class="text-muted">Model</span>
                        <div class="fw-semibold">{{ $order->displayDeviceModelName() ?: '—' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-4">
                        <span class="text-muted">Serial Number</span>
                        <div class="fw-semibold d-flex flex-wrap align-items-center gap-2">
                            <span>{{ $order->serial_number ?: '—' }}</span>
                            @if($order->serial_number)
                                @include('orders.partials.serial-validation-badge', ['order' => $order])
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="row g-2 small">
                    <div class="col-6">
                        <span class="text-muted">Total Service Cases</span>
                        <div class="fs-5 fw-semibold">{{ $order->incidents_count }}</div>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Open Service Cases</span>
                        <div class="fs-5 fw-semibold">{{ $order->openIncidentsCount() }}</div>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Last Service Case</span>
                        <div class="fw-semibold">{{ $latestIncident ? display_app_date($latestIncident->created_at) : '—' }}</div>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Assigned To</span>
                        <div class="fw-semibold">{{ $assignedTeamMember }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
