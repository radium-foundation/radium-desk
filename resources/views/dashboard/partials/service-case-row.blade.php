@php
    use App\Enums\SerialValidationSeverity;
    use App\Services\SerialValidation\SerialPlaceholderService;
    use App\Services\SerialValidation\SerialValidationService;
    use App\Support\Dashboard\ScheduledAppointmentRowBadgePresenter;

    $order = $serviceCase->order;
    $isCompleted = $order?->isTransactionLocked() ?? false;
    $searchParts = array_filter([
        $order?->order_id,
        $serviceCase->display_reference,
        $order?->customer_name,
        $order?->customer_email,
        $order?->customer_phone,
        $order?->serial_number,
        $order?->displayDeviceModelName(),
    ], fn ($value) => filled($value));
    $searchText = strtolower(implode(' ', $searchParts));
    $isScheduledWorkspace = (bool) ($isScheduledWorkspace ?? false);
    $scheduledAppointmentPresentation = $isScheduledWorkspace
        ? app(ScheduledAppointmentRowBadgePresenter::class)->present($serviceCase)
        : null;
    $serialValidation = null;

    if ($order !== null
        && filled($order->serial_number)
        && ! app(SerialPlaceholderService::class)->isPlaceholder((string) $order->serial_number)) {
        $serialValidation = app(SerialValidationService::class)->validateForOrder((string) $order->serial_number, $order);
    }

    $compactAgentLayout = $compactAgentLayout ?? false;
@endphp

<tr id="service-case-row-{{ $serviceCase->id }}"
    data-incident-id="{{ $serviceCase->id }}"
    data-order-id="{{ $order?->id }}"
    data-search-text="{{ e($searchText) }}"
    @class([
        'dashboard-case-row--clickable' => true,
        'dashboard-case-row--completed' => $isCompleted,
        'dashboard-case-row--pending' => $order && ! $isCompleted,
    ])>
    @if($canSelectRows ?? false)
        <td class="dashboard-select-cell">
            @if($order && ! $isCompleted && ! $order->isInquiryOrder())
                <input type="checkbox"
                       class="form-check-input service-case-select"
                       value="{{ $serviceCase->id }}"
                       data-order-id="{{ $order->id }}"
                       aria-label="Select {{ $serviceCase->display_reference }}">
            @endif
        </td>
    @endif
    <td class="case-reference-cell">
        <div class="d-flex flex-wrap align-items-center gap-1">
            <a href="{{ route('incidents.show', $serviceCase) }}" class="case-reference-link text-decoration-none">
                {{ $serviceCase->display_reference }}
            </a>
            @if($serviceCase->high_priority)
                @include('dashboard.partials.high-priority-badge')
            @endif
        </div>
    </td>
    <td class="case-order-cell case-meta-cell">
        @if($order && ! $order->isInquiryOrder())
            <x-order-identifier
                :order="$order"
                :incident="$serviceCase"
                :href="route('orders.show', $order)"
            />
        @else
            —
        @endif
    </td>
    @include('dashboard.partials.serial-number-cell', ['serviceCase' => $serviceCase])
    <td @class(['status-sla-cell', 'appointment-status-cell' => $isScheduledWorkspace])>
        @include('dashboard.partials.status-sla-cell', [
            'serviceCase' => $serviceCase,
            'order' => $order,
            'isScheduledWorkspace' => $isScheduledWorkspace,
            'scheduledAppointmentPresentation' => $scheduledAppointmentPresentation,
        ])
    </td>
    @include('dashboard.partials.transaction-id-cell', [
        'serviceCase' => $serviceCase,
        'canManageTransactions' => $canManageTransactions ?? false,
        'requiresLegacyVerification' => $requiresLegacyVerification
            ?? ($order !== null && app(\App\Services\CustomerVerificationService::class)->requiresLegacyVerification($order)),
        'legacyVerificationUrl' => $legacyVerificationUrl
            ?? ($order !== null ? route('orders.legacy-verification.store', $order) : null),
        'legacyVerificationMode' => $legacyVerificationMode
            ?? ($order !== null
                ? app(\App\Services\CustomerVerificationService::class)->legacyVerificationMode($order)
                : 'customer'),
    ])
    <td class="source-cell d-none d-md-table-cell">
        @include('dashboard.partials.source-icon', ['source' => $serviceCase->source])
    </td>
    <td class="case-meta-cell dashboard-people-cell d-none d-md-table-cell">
        <div class="dashboard-people-avatars">
            @if($serviceCase->assignee)
                <x-dashboard-user-avatar :user="$serviceCase->assignee" aria-prefix="Assigned To" />
            @endif
            @if($serviceCase->creator)
                <x-dashboard-user-avatar :user="$serviceCase->creator" aria-prefix="Logged by" />
            @endif
            @if(! $serviceCase->assignee && ! $serviceCase->creator)
                —
            @endif
        </div>
    </td>
    <td class="case-meta-cell dashboard-timeline-cell-wrap d-none d-lg-table-cell">
        @include('dashboard.partials.timeline-cell', ['serviceCase' => $serviceCase])
    </td>
    @include('dashboard.partials.device-model-cell', ['serviceCase' => $serviceCase])
</tr>
