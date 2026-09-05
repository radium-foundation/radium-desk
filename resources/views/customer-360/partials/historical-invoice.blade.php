@if(is_array($historicalInvoice ?? null))
    <section class="customer-360-section"
             data-customer-360-section="historical-invoice"
             aria-labelledby="customer-360-historical-invoice-heading">
        <h3 class="customer-360-section-title" id="customer-360-historical-invoice-heading">Historical invoice</h3>
        <p class="mb-2">
            <span class="fw-semibold">{{ $historicalInvoice['invoice_number'] }}</span>
            <span class="text-muted"> · read-only reprint · exact original number</span>
        </p>
        @if(filled($historicalInvoice['print_url'] ?? null))
            <a class="btn btn-sm btn-outline-primary"
               href="{{ $historicalInvoice['print_url'] }}"
               target="_blank"
               rel="noopener"
               data-customer-360-historical-print>
                Print
            </a>
        @endif
    </section>
@endif
