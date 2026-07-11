@php
    $phone = trim((string) ($customer['mobile'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    $whatsappUrl = strlen($phoneDigits) >= 10
        ? 'https://wa.me/'.(str_starts_with($phoneDigits, '91') ? $phoneDigits : '91'.$phoneDigits)
        : null;
    $telUrl = $phone !== '' ? 'tel:'.$phone : null;
    $hideWorkflowActions = $hideWorkflowActions ?? false;
    $hasRecommendedActions = $hasRecommendedActions ?? false;
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

        <button type="button"
                class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                disabled
                title="Email integration coming soon"
                data-bs-toggle="tooltip"
                data-bs-placement="top">
            <span aria-hidden="true">📧</span> Email
        </button>
    </div>

    @if($hasRecommendedActions && ! $hideWorkflowActions)
        <h3 class="customer-360-section-title customer-360-section-title--subsequent"
            id="customer-360-recommended-actions-heading">
            Recommended Actions
        </h3>
        <div class="customer-360-recommended-actions"
             aria-labelledby="customer-360-recommended-actions-heading">
            @if($serialRequestState['requested'] ?? false)
                <article class="customer-360-recommended-action-card customer-360-recommended-action-card--completed">
                    <h4 class="customer-360-recommended-action-title">Serial number requested</h4>
                    <p class="customer-360-recommended-action-description">
                        Waiting for customer to share device serial number.
                    </p>
                    <p class="customer-360-recommended-action-status">
                        <span aria-hidden="true">✓</span> Request sent
                        @if(filled($serialRequestState['requested_at_label'] ?? null))
                            <span class="customer-360-recommended-action-status-at">{{ $serialRequestState['requested_at_label'] }}</span>
                        @endif
                    </p>
                </article>
            @elseif($canRequestSerialNumber ?? false)
                <article class="customer-360-recommended-action-card">
                    <h4 class="customer-360-recommended-action-title">Serial number missing</h4>
                    <p class="customer-360-recommended-action-description">
                        Ask customer to provide device serial number.
                    </p>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm customer-360-recommended-action-button"
                            data-workspace-trigger="request-serial"
                            data-workspace-incident-id="{{ $incident->id }}"
                            data-workspace-context="customer">
                        Request Serial Number
                    </button>
                </article>
            @endif

            @if($correctSerialRequestState['requested'] ?? false)
                <article class="customer-360-recommended-action-card customer-360-recommended-action-card--completed">
                    <h4 class="customer-360-recommended-action-title">Serial correction requested</h4>
                    <p class="customer-360-recommended-action-description">
                        Waiting for customer to share correct serial photo.
                    </p>
                    <p class="customer-360-recommended-action-status">
                        <span aria-hidden="true">✓</span> Request sent
                        @if(filled($correctSerialRequestState['requested_at_label'] ?? null))
                            <span class="customer-360-recommended-action-status-at">{{ $correctSerialRequestState['requested_at_label'] }}</span>
                        @endif
                    </p>
                </article>
            @elseif($canRequestCorrectSerial ?? false)
                <article class="customer-360-recommended-action-card">
                    <h4 class="customer-360-recommended-action-title">Serial number needs verification</h4>
                    <p class="customer-360-recommended-action-description">
                        The current serial number may be incorrect. Request customer to share correct serial photo.
                    </p>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm customer-360-recommended-action-button"
                            data-workspace-trigger="request-correct-serial"
                            data-workspace-incident-id="{{ $incident->id }}"
                            data-workspace-context="customer">
                        Request Correct Serial
                    </button>
                    <p class="customer-360-recommended-action-note">
                        Moves case to Waiting Customer after sending request.
                    </p>
                </article>
            @endif

            @if($canCustomerNotResponding ?? false)
                <article class="customer-360-recommended-action-card">
                    <h4 class="customer-360-recommended-action-title">Customer unreachable</h4>
                    <p class="customer-360-recommended-action-description">
                        Send callback scheduling link and pause until customer responds.
                    </p>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm customer-360-recommended-action-button"
                            data-workspace-trigger="customer-not-responding"
                            data-workspace-incident-id="{{ $incident->id }}"
                            data-workspace-context="customer">
                        Customer Not Responding
                    </button>
                </article>
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
        </div>
    @endif

    @if(($canCorrectCustomerDetails ?? false) || ($canCorrectSerialNumber ?? false))
        <div class="customer-360-quick-actions customer-360-quick-actions--admin mt-3">
            @if($canCorrectCustomerDetails ?? false)
                <button type="button"
                        class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                        data-workspace-trigger="correct-customer-details"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="customer"
                        title="Correct customer name, phone, or email on the order">
                    <span aria-hidden="true">✏️</span> Correct Customer Details
                </button>
            @endif
            @if($canCorrectSerialNumber ?? false)
                <button type="button"
                        class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                        data-workspace-trigger="correct-serial-number"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="customer"
                        title="Correct the serial number on the order">
                    <span aria-hidden="true">🔢</span> Correct Serial Number
                </button>
            @endif
        </div>
    @endif

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
