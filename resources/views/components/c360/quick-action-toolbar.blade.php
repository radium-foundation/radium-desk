@props([
    'incident',
    'order' => null,
    'customer' => [],
    'canRequestSerialNumber' => false,
    'canRequestCorrectSerial' => false,
    'canCustomerNotResponding' => false,
    'canLinkOrder' => false,
    'canCorrectCustomerDetails' => false,
    'canCorrectSerialNumber' => false,
    'correctCustomerDetailsEligibility' => ['allowed' => false, 'reason' => null],
    'correctSerialNumberEligibility' => ['allowed' => false, 'reason' => null],
    'showIdentityCorrectionActions' => false,
    'hideWorkflowActions' => false,
    'hasRecommendedActions' => false,
    'serialRequestState' => ['requested' => false],
    'correctSerialRequestState' => ['requested' => false],
    'supportAppointments' => null,
])

@php
    $phone = trim((string) ($customer['mobile'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    $whatsappUrl = strlen($phoneDigits) >= 10
        ? 'https://wa.me/'.(str_starts_with($phoneDigits, '91') ? $phoneDigits : '91'.$phoneDigits)
        : null;
    $telUrl = $phone !== '' ? 'tel:'.$phone : null;

    $primaryAction = null;
    $moreActions = [];

    if ($hasRecommendedActions && ! $hideWorkflowActions) {
        if ($serialRequestState['requested'] ?? false) {
            $primaryAction = [
                'type' => 'status',
                'label' => 'Serial requested',
                'icon' => 'bi-check-circle',
            ];
        } elseif ($canRequestSerialNumber) {
            $primaryAction = [
                'type' => 'trigger',
                'label' => 'Request Serial',
                'icon' => 'bi-upc-scan',
                'trigger' => 'request-serial',
            ];
        } elseif ($correctSerialRequestState['requested'] ?? false) {
            $primaryAction = [
                'type' => 'status',
                'label' => 'Correction requested',
                'icon' => 'bi-check-circle',
            ];
        } elseif ($canRequestCorrectSerial) {
            $primaryAction = [
                'type' => 'trigger',
                'label' => 'Request Correct Serial',
                'icon' => 'bi-camera',
                'trigger' => 'request-correct-serial',
            ];
        } elseif ($canCustomerNotResponding) {
            $primaryAction = [
                'type' => 'trigger',
                'label' => 'Customer Not Responding',
                'icon' => 'bi-hourglass-split',
                'trigger' => 'customer-not-responding',
            ];
        } elseif ($canLinkOrder) {
            $primaryAction = [
                'type' => 'trigger',
                'label' => 'Link Order',
                'icon' => 'bi-link-45deg',
                'trigger' => 'link-order',
            ];
        }

        if ($canRequestSerialNumber && ($primaryAction['trigger'] ?? null) !== 'request-serial' && ! ($serialRequestState['requested'] ?? false)) {
            $moreActions[] = ['type' => 'trigger', 'label' => 'Request Serial', 'trigger' => 'request-serial', 'icon' => 'bi-upc-scan', 'enabled' => true];
        }
        if ($canRequestCorrectSerial && ($primaryAction['trigger'] ?? null) !== 'request-correct-serial' && ! ($correctSerialRequestState['requested'] ?? false)) {
            $moreActions[] = ['type' => 'trigger', 'label' => 'Request Correct Serial', 'trigger' => 'request-correct-serial', 'icon' => 'bi-camera', 'enabled' => true];
        }
        if ($canCustomerNotResponding && ($primaryAction['trigger'] ?? null) !== 'customer-not-responding') {
            $moreActions[] = ['type' => 'trigger', 'label' => 'Customer Not Responding', 'trigger' => 'customer-not-responding', 'icon' => 'bi-hourglass-split', 'enabled' => true];
        }
        if ($canLinkOrder && ($primaryAction['trigger'] ?? null) !== 'link-order') {
            $moreActions[] = ['type' => 'trigger', 'label' => 'Link Order', 'trigger' => 'link-order', 'icon' => 'bi-link-45deg', 'enabled' => true];
        }
    }

    if ($showIdentityCorrectionActions) {
        $moreActions[] = [
            'type' => 'trigger',
            'label' => 'Correct Customer',
            'trigger' => 'correct-customer-details',
            'icon' => 'bi-person-gear',
            'shortcut' => 'correct-customer',
            'enabled' => (bool) ($correctCustomerDetailsEligibility['allowed'] ?? false),
            'disabledReason' => $correctCustomerDetailsEligibility['reason'] ?? 'Action is not available.',
        ];
        $moreActions[] = [
            'type' => 'trigger',
            'label' => 'Correct Serial',
            'trigger' => 'correct-serial-number',
            'icon' => 'bi-upc',
            'shortcut' => 'correct-serial',
            'enabled' => (bool) ($correctSerialNumberEligibility['allowed'] ?? false),
            'disabledReason' => $correctSerialNumberEligibility['reason'] ?? 'Action is not available.',
        ];
    }
    if ($supportAppointments !== null && $supportAppointments->isNotEmpty()) {
        $moreActions[] = ['type' => 'link', 'label' => 'Appointments', 'href' => route('incidents.show', $incident).'#support-appointments', 'icon' => 'bi-calendar-event'];
    }
    if ($order) {
        $moreActions[] = ['type' => 'link', 'label' => 'Open Order', 'href' => route('orders.show', $order), 'icon' => 'bi-box-arrow-up-right'];
    }
    $moreActions[] = ['type' => 'link', 'label' => 'Open Case', 'href' => route('incidents.show', $incident), 'icon' => 'bi-folder2-open'];
    $moreActions[] = ['type' => 'link', 'label' => 'Refund', 'href' => route('refunds.create'), 'icon' => 'bi-arrow-counterclockwise'];
@endphp

<nav {{ $attributes->merge(['class' => 'c360-quick-toolbar']) }}
     data-customer-360-section="quick-actions"
     data-c360-quick-toolbar
     aria-label="Quick actions">
    @if($primaryAction)
        <div class="c360-quick-toolbar-recommended">
            @if(($primaryAction['type'] ?? '') === 'trigger')
                <button type="button"
                        class="c360-quick-toolbar-primary"
                        data-workspace-trigger="{{ $primaryAction['trigger'] }}"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="customer">
                    <i class="bi {{ $primaryAction['icon'] }}" aria-hidden="true"></i>
                    <span>{{ $primaryAction['label'] }}</span>
                </button>
            @else
                <span class="c360-quick-toolbar-primary c360-quick-toolbar-primary--status" role="status">
                    <i class="bi {{ $primaryAction['icon'] }}" aria-hidden="true"></i>
                    <span>{{ $primaryAction['label'] }}</span>
                </span>
            @endif
        </div>
    @endif

    <div class="c360-quick-toolbar-actions">
        @if($telUrl)
            <a href="{{ $telUrl }}"
               class="c360-quick-toolbar-btn"
               title="Call customer (C)"
               aria-label="Call customer"
               data-c360-shortcut-action="call">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <span>Call</span>
            </a>
        @else
            <button type="button"
                    class="c360-quick-toolbar-btn"
                    disabled
                    title="No phone number"
                    aria-label="Call customer unavailable"
                    data-c360-shortcut-action="call">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <span>Call</span>
            </button>
        @endif

        @if($whatsappUrl)
            <a href="{{ $whatsappUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="c360-quick-toolbar-btn"
               title="Open WhatsApp (W)"
               aria-label="Open WhatsApp"
               data-c360-shortcut-action="whatsapp">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span>WhatsApp</span>
            </a>
        @else
            <button type="button"
                    class="c360-quick-toolbar-btn"
                    disabled
                    title="No phone number"
                    aria-label="WhatsApp unavailable"
                    data-c360-shortcut-action="whatsapp">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span>WhatsApp</span>
            </button>
        @endif

        <button type="button"
                class="c360-quick-toolbar-btn"
                disabled
                title="Email integration coming soon"
                aria-label="Email unavailable"
                data-c360-shortcut-action="email">
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <span>Email</span>
        </button>

        <div class="c360-quick-toolbar-more-wrap">
            <button type="button"
                    class="c360-quick-toolbar-btn c360-quick-toolbar-btn--more"
                    data-c360-quick-more-toggle
                    aria-expanded="false"
                    aria-haspopup="true"
                    aria-controls="c360-quick-more-{{ $incident->id }}">
                <i class="bi bi-three-dots" aria-hidden="true"></i>
                <span>More</span>
            </button>
            <div class="c360-quick-toolbar-more-menu"
                 id="c360-quick-more-{{ $incident->id }}"
                 data-c360-quick-more-menu
                 role="menu"
                 hidden>
                @foreach($moreActions as $action)
                    @if(($action['type'] ?? '') === 'trigger')
                        @php
                            $actionEnabled = (bool) ($action['enabled'] ?? true);
                            $disabledReason = trim((string) ($action['disabledReason'] ?? 'Action is not available.'));
                        @endphp
                        <button type="button"
                                @class([
                                    'c360-quick-toolbar-more-item',
                                    'c360-quick-toolbar-more-item--disabled' => ! $actionEnabled,
                                ])
                                role="menuitem"
                                @if($actionEnabled)
                                    data-workspace-trigger="{{ $action['trigger'] }}"
                                    data-workspace-incident-id="{{ $incident->id }}"
                                    data-workspace-context="customer"
                                    @if(filled($action['shortcut'] ?? null)) data-c360-shortcut-action="{{ $action['shortcut'] }}" @endif
                                @else
                                    disabled
                                    title="🔒 {{ $disabledReason }}"
                                    aria-disabled="true"
                                @endif>
                            <span class="c360-quick-toolbar-more-item-main">
                                <i class="bi {{ $action['icon'] }}" aria-hidden="true"></i>
                                <span>{{ $action['label'] }}</span>
                            </span>
                            @unless($actionEnabled)
                                <span class="c360-quick-toolbar-more-item-hint">🔒 {{ $disabledReason }}</span>
                            @endunless
                        </button>
                    @else
                        <a href="{{ $action['href'] }}"
                           class="c360-quick-toolbar-more-item"
                           role="menuitem"
                           @if(str_starts_with($action['href'], 'http')) target="_blank" rel="noopener noreferrer" @endif>
                            <span class="c360-quick-toolbar-more-item-main">
                                <i class="bi {{ $action['icon'] }}" aria-hidden="true"></i>
                                <span>{{ $action['label'] }}</span>
                            </span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</nav>
