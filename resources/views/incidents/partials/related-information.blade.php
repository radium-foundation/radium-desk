@props(['incident', 'linkableApprovals' => collect()])

@php
    $canViewApprovals = auth()->user()?->can('approvals.view') ?? false;
    $canManageApprovals = (auth()->user()?->can('approvals.create') ?? false)
        || (auth()->user()?->can('approvals.link') ?? false);
    $hasApprovals = $incident->approvalNumbers->isNotEmpty();
    $showApprovalSection = $canViewApprovals && ($hasApprovals || $canManageApprovals);
    $hasRefunds = $incident->refundRequests->isNotEmpty();
    $hasOrder = $incident->order !== null;
@endphp

@if($hasOrder || $showApprovalSection || $hasRefunds)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">{{ config('ui.service_case.related_information_heading') }}</h2>
        </div>
        <div class="card-body">
            @if($hasOrder)
                <div class="mb-3 pb-3 @if($showApprovalSection || $hasRefunds) border-bottom @endif">
                    <h3 class="h6 text-muted small text-uppercase mb-2">
                        {{ $incident->order->isInquiryOrder() ? 'Enquiry' : 'Order' }}
                    </h3>
                    <x-order-identifier
                        :order="$incident->order"
                        :incident="$incident"
                        :href="$incident->order->isInquiryOrder() ? null : route('orders.show', $incident->order)"
                        class="fw-semibold"
                    />
                    @unless($incident->order->isInquiryOrder())
                        <span class="text-muted mx-1">·</span>
                        @include('orders.partials.completion-status-badge', ['order' => $incident->order])
                    @endunless
                </div>
            @endif

            @include('incidents.partials.approval-numbers-section', [
                'incident' => $incident,
                'linkableApprovals' => $linkableApprovals,
            ])

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
