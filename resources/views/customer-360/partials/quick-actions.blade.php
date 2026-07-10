@php
    $phone = trim((string) ($customer['mobile'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    $whatsappUrl = strlen($phoneDigits) >= 10
        ? 'https://wa.me/'.(str_starts_with($phoneDigits, '91') ? $phoneDigits : '91'.$phoneDigits)
        : null;
    $telUrl = $phone !== '' ? 'tel:'.$phone : null;
@endphp

<section class="customer-360-section" data-customer-360-section="quick-actions" aria-labelledby="customer-360-actions-heading">
    <h3 class="customer-360-section-title" id="customer-360-actions-heading">Quick Actions</h3>
    <div class="customer-360-quick-actions">
        @if($telUrl)
            <a href="{{ $telUrl }}"
               class="btn btn-outline-secondary btn-sm customer-360-quick-action"
               title="Call customer">
                <span aria-hidden="true">📞</span> Call
            </a>
        @else
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    disabled
                    title="No phone number available"
                    data-bs-toggle="tooltip"
                    data-bs-placement="top">
                <span aria-hidden="true">📞</span> Call
            </button>
        @endif

        @if($whatsappUrl)
            <a href="{{ $whatsappUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm customer-360-quick-action"
               title="Open WhatsApp chat">
                <span aria-hidden="true">💬</span> WhatsApp
            </a>
        @else
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    disabled
                    title="No phone number available"
                    data-bs-toggle="tooltip"
                    data-bs-placement="top">
                <span aria-hidden="true">💬</span> WhatsApp
            </button>
        @endif

        @if($serialRequestState['requested'] ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action customer-360-quick-action--serial-requested"
                    disabled
                    title="Serial number request already sent">
                <span class="customer-360-serial-requested-content">
                    <span class="customer-360-serial-requested-label">
                        <span aria-hidden="true">✓</span> Serial Requested
                    </span>
                    @if(filled($serialRequestState['requested_at_label'] ?? null))
                        <span class="customer-360-serial-requested-at">{{ $serialRequestState['requested_at_label'] }}</span>
                    @endif
                </span>
            </button>
        @elseif($canRequestSerialNumber ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-workspace-trigger="request-serial"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="customer"
                    title="Send approved WhatsApp template to request serial number">
                <span aria-hidden="true">📱</span> Request Serial Number
            </button>
        @endif

        @if($correctSerialRequestState['requested'] ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action customer-360-quick-action--serial-requested"
                    disabled
                    title="Serial correction request already sent">
                <span class="customer-360-serial-requested-content">
                    <span class="customer-360-serial-requested-label">
                        <span aria-hidden="true">✓</span> Correction Requested
                    </span>
                    @if(filled($correctSerialRequestState['requested_at_label'] ?? null))
                        <span class="customer-360-serial-requested-at">{{ $correctSerialRequestState['requested_at_label'] }}</span>
                    @endif
                </span>
            </button>
        @elseif($canRequestCorrectSerial ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-workspace-trigger="request-correct-serial"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="customer"
                    title="Ask customer to confirm the correct device serial number">
                <span aria-hidden="true">🔁</span> Request Correct Serial
            </button>
        @endif

        @if($canCustomerNotResponding ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-workspace-trigger="customer-not-responding"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="customer"
                    title="Send callback schedule message when customer is not responding">
                <span aria-hidden="true">📵</span> Customer Not Responding
            </button>
        @endif

        @if($canLinkOrder ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-workspace-trigger="link-order"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="customer"
                    title="Link this enquiry to the customer's real order">
                <span aria-hidden="true">🔗</span> Link Order
            </button>
        @endif

        <button type="button"
                class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                disabled
                title="Email integration coming soon"
                data-bs-toggle="tooltip"
                data-bs-placement="top">
            <span aria-hidden="true">📧</span> Email
        </button>
    </div>

    <div class="customer-360-quick-actions customer-360-quick-actions--secondary">
        @if($order)
            <a href="{{ route('orders.show', $order) }}"
               class="btn btn-link btn-sm customer-360-quick-action-link">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                Open Order
            </a>
        @endif
        <a href="{{ route('incidents.show', $incident) }}"
           class="btn btn-link btn-sm customer-360-quick-action-link">
            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
            Open Case
        </a>
    </div>
</section>
