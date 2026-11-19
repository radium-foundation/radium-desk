<section class="customer-360-section"
         data-customer-360-section="statutory-invoice"
         aria-labelledby="customer-360-statutory-invoice-heading">
    <h3 class="customer-360-section-title" id="customer-360-statutory-invoice-heading">Invoice</h3>
    @forelse(($statutoryInvoices ?? []) as $invoice)
        <div class="c360-statutory-invoice-card" data-c360-invoice-id="{{ $invoice['id'] }}">
            <dl class="c360-statutory-invoice-meta">
                <div>
                    <dt>Invoice No.</dt>
                    <dd>{{ $invoice['invoice_number'] }}</dd>
                </div>
                @if(filled($invoice['issued_at_label'] ?? null))
                    <div>
                        <dt>Invoice Date</dt>
                        <dd>{{ $invoice['issued_at_label'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt>Invoice Status</dt>
                    <dd>{{ $invoice['status_label'] }}</dd>
                </div>
            </dl>
            @if(filled($invoice['service_b2c_note'] ?? null))
                <p class="small text-muted mb-2" role="status">{{ $invoice['service_b2c_note'] }}</p>
            @endif
            <div class="c360-statutory-invoice-actions">
                <a class="btn btn-sm btn-outline-primary"
                   href="{{ $invoice['view_url'] }}"
                   target="_blank"
                   rel="noopener"
                   data-c360-invoice-view>
                    View Invoice
                </a>
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ $invoice['download_url'] }}"
                   data-c360-invoice-download>
                    Download PDF
                </a>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-c360-invoice-native-share
                        data-c360-invoice-share-url="{{ $invoice['share_url'] }}"
                        data-c360-invoice-share-title="{{ $invoice['share_title'] }}">
                    Share Invoice
                </button>
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
            @if(is_array($invoice['einvoice'] ?? null))
                <div class="c360-einvoice-block" data-c360-einvoice>
                    <h4 class="c360-einvoice-heading">E-INVOICE</h4>
                    <p class="c360-einvoice-status mb-0">
                        Status: {{ $invoice['einvoice']['status_label'] }}
                    </p>
                    @if(filled($invoice['einvoice']['irn'] ?? null))
                        <p class="mb-0">IRN: {{ $invoice['einvoice']['irn'] }}</p>
                    @endif
                    @if(filled($invoice['einvoice']['ack_no'] ?? null))
                        <p class="mb-0">Ack No: {{ $invoice['einvoice']['ack_no'] }}</p>
                    @endif
                    @if(filled($invoice['einvoice']['ack_date'] ?? null))
                        <p class="mb-0">Ack Date: {{ $invoice['einvoice']['ack_date'] }}</p>
                    @endif
                    @if(filled($invoice['einvoice']['why'] ?? null))
                        <p class="mb-0 text-muted">Why? {{ $invoice['einvoice']['why'] }}</p>
                    @endif
                    @if(filled($invoice['einvoice']['next_action'] ?? null))
                        <p class="mb-0 text-muted">Next action: {{ $invoice['einvoice']['next_action'] }}</p>
                    @endif
                </div>
            @endif
        </div>
    @empty
        @php($mintOutbox = $statutoryMintOutbox ?? null)
        @if(is_array($mintOutbox))
            <div class="c360-statutory-mint-outbox"
                 data-c360-statutory-mint-outbox
                 role="status"
                 aria-live="polite">
                <p class="c360-statutory-invoice-empty mb-2" data-c360-invoice-empty>
                    {{ $mintOutbox['empty_summary'] ?? 'No invoice has been generated for this order.' }}
                </p>
                <dl class="c360-statutory-invoice-meta mb-0">
                    <div>
                        <dt>Mint status</dt>
                        <dd>{{ $mintOutbox['status_label'] ?? '—' }}</dd>
                    </div>
                    @if(filled($mintOutbox['updated_at_label'] ?? null))
                        <div>
                            <dt>Last updated</dt>
                            <dd>{{ $mintOutbox['updated_at_label'] }}</dd>
                        </div>
                    @endif
                    @if(filled($mintOutbox['order_reference'] ?? null))
                        <div>
                            <dt>Order ref.</dt>
                            <dd>{{ $mintOutbox['order_reference'] }}</dd>
                        </div>
                    @endif
                    @if(filled($mintOutbox['service_case_reference'] ?? null))
                        <div>
                            <dt>Service case</dt>
                            <dd>{{ $mintOutbox['service_case_reference'] }}</dd>
                        </div>
                    @endif
                    @if(filled($mintOutbox['failure_reason'] ?? null))
                        <div>
                            <dt>Failure reason</dt>
                            <dd>{{ $mintOutbox['failure_reason'] }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        @else
            <p class="c360-statutory-invoice-empty mb-0" data-c360-invoice-empty>
                No invoice has been generated for this order.
            </p>
        @endif
    @endforelse
</section>
