@props(['incident'])

@php
    $hasApprovals = $incident->approvalNumbers->isNotEmpty();
    $hasRefunds = $incident->refundRequests->isNotEmpty();
    $hasOrder = $incident->order !== null;
@endphp

@if($hasOrder || $hasApprovals || $hasRefunds)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">{{ config('ui.service_case.related_information_heading') }}</h2>
        </div>
        <div class="card-body">
            @if($hasOrder)
                <div class="mb-3 pb-3 @if($hasApprovals || $hasRefunds) border-bottom @endif">
                    <h3 class="h6 text-muted small text-uppercase mb-2">Order</h3>
                    <a href="{{ route('orders.show', $incident->order) }}" class="fw-semibold text-decoration-none">
                        {{ $incident->order->order_id }}
                    </a>
                    <span class="text-muted mx-1">·</span>
                    @include('orders.partials.completion-status-badge', ['order' => $incident->order])
                </div>
            @endif

            @if($hasApprovals)
                <div class="mb-3 @if($hasRefunds) pb-3 border-bottom @endif">
                    <h3 class="h6 text-muted small text-uppercase mb-2">Approval</h3>
                    <ul class="list-unstyled mb-0">
                        @foreach($incident->approvalNumbers as $approval)
                            <li class="mb-1">
                                <a href="{{ route('approvals.show', $approval) }}" class="text-decoration-none fw-semibold">
                                    {{ $approval->approval_number }}
                                </a>
                                @if($approval->description)
                                    <span class="text-muted small">— {{ $approval->description }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($hasRefunds)
                <div>
                    <h3 class="h6 text-muted small text-uppercase mb-2">Refund</h3>
                    <ul class="list-unstyled mb-0">
                        @foreach($incident->refundRequests as $refund)
                            <li class="mb-1">
                                <a href="{{ route('refunds.show', $refund) }}" class="text-decoration-none fw-semibold">
                                    {{ $refund->reference_no }}
                                </a>
                                <span class="badge text-bg-secondary ms-1">{{ $refund->status->label() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif
