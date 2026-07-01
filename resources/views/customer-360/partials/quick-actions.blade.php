@php
    $phone = trim((string) ($customer['mobile'] ?? ''));
    $email = trim((string) ($customer['email'] ?? ''));
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

        @if($canRequestSerialNumber ?? false)
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-workspace-trigger="request-serial"
                    data-workspace-incident-id="{{ $incident->id }}"
                    data-workspace-context="customer"
                    title="Send approved WhatsApp template to request serial number">
                <span aria-hidden="true">📱</span> Request Serial Number
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

        @if($phone !== '')
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-customer-360-copy="phone"
                    data-copy-value="{{ $phone }}"
                    title="Copy phone number">
                <span aria-hidden="true">📋</span> Copy Phone
            </button>
        @else
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    disabled
                    title="No phone number available"
                    data-bs-toggle="tooltip"
                    data-bs-placement="top">
                <span aria-hidden="true">📋</span> Copy Phone
            </button>
        @endif

        @if($email !== '')
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-customer-360-copy="email"
                    data-copy-value="{{ $email }}"
                    title="Copy email address">
                <span aria-hidden="true">📋</span> Copy Email
            </button>
        @else
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    disabled
                    title="No email address available"
                    data-bs-toggle="tooltip"
                    data-bs-placement="top">
                <span aria-hidden="true">📋</span> Copy Email
            </button>
        @endif
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
        @if(filled($device['serial_number'] ?? null))
            <button type="button"
                    class="btn btn-link btn-sm customer-360-quick-action-link"
                    data-customer-360-copy="serial"
                    data-copy-value="{{ $device['serial_number'] }}">
                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                Copy Serial
            </button>
        @endif
    </div>
</section>
