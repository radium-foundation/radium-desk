@if(($statutoryInvoices ?? []) !== [])
    <section class="customer-360-section"
             data-customer-360-section="statutory-invoice"
             aria-labelledby="customer-360-statutory-invoice-heading">
        <h3 class="customer-360-section-title" id="customer-360-statutory-invoice-heading">Invoice</h3>
        @foreach($statutoryInvoices as $invoice)
            <div class="c360-statutory-invoice-card" data-c360-invoice-id="{{ $invoice['id'] }}">
                <p class="mb-2">
                    <span class="fw-semibold">{{ $invoice['invoice_number'] }}</span>
                    @if(filled($invoice['issued_at_label'] ?? null))
                        <span class="text-muted"> · {{ $invoice['issued_at_label'] }}</span>
                    @endif
                    @if(filled($invoice['invoice_value'] ?? null))
                        <span class="text-muted"> · Rs.{{ $invoice['invoice_value'] }}</span>
                    @endif
                    <span class="text-muted"> · {{ $invoice['status_label'] }}</span>
                </p>
                <div class="c360-statutory-invoice-actions">
                    <a class="btn btn-sm btn-outline-primary"
                       href="{{ $invoice['view_url'] }}"
                       target="_blank"
                       rel="noopener"
                       data-c360-invoice-view>
                        View
                    </a>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="{{ $invoice['download_url'] }}"
                       data-c360-invoice-download>
                        Download PDF
                    </a>
                    @if($invoice['can_email'])
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                data-c360-invoice-share="{{ $invoice['email_url'] }}">
                            Email
                        </button>
                    @else
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                disabled
                                title="{{ $invoice['email_unavailable_reason'] }}">
                            Email
                        </button>
                    @endif
                    @if($invoice['can_whatsapp'])
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                data-c360-invoice-share="{{ $invoice['whatsapp_url'] }}">
                            WhatsApp
                        </button>
                    @else
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                disabled
                                title="{{ $invoice['whatsapp_unavailable_reason'] }}">
                            WhatsApp
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </section>
@endif
