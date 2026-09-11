@php($presentation = $statutoryPresentation ?? null)
@if(is_array($presentation))
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 text-muted">GST tax invoice</h2>
            @if($presentation['minted'] ?? false)
                <p class="mb-2">
                    <span class="fw-semibold">{{ $presentation['invoice_number'] }}</span>
                    @if(filled($presentation['issued_at_label'] ?? null))
                        <span class="text-muted">· {{ $presentation['issued_at_label'] }}</span>
                    @endif
                </p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <a class="btn btn-sm btn-outline-primary" href="{{ $presentation['view_url'] }}" target="_blank" rel="noopener">View invoice</a>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $presentation['download_url'] }}">Download PDF</a>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            data-pos-invoice-share
                            data-share-url="{{ $presentation['share_url'] }}"
                            data-share-title="{{ $presentation['share_title'] }}">
                        Share
                    </button>
                    @if($presentation['can_email'] ?? false)
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                data-pos-invoice-email
                                data-email-url="{{ $presentation['email_url'] }}"
                                data-customer-email="{{ $presentation['customer_email'] ?? '' }}">
                            Email
                        </button>
                    @else
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="{{ $presentation['email_unavailable_reason'] ?? 'Email unavailable' }}">Email</button>
                    @endif
                </div>
                @if(is_array($presentation['einvoice'] ?? null))
                    <div class="small border-top pt-3">
                        <div class="text-muted text-uppercase fw-semibold mb-1">E-invoice</div>
                        <div>Status: {{ $presentation['einvoice']['status_label'] }}</div>
                        @if(filled($presentation['einvoice']['irn'] ?? null))
                            <div>IRN: {{ $presentation['einvoice']['irn'] }}</div>
                        @endif
                        @if(filled($presentation['einvoice']['ack_no'] ?? null))
                            <div>Ack No: {{ $presentation['einvoice']['ack_no'] }}</div>
                        @endif
                        @if(filled($presentation['einvoice']['ack_date'] ?? null))
                            <div>Ack Date: {{ $presentation['einvoice']['ack_date'] }}</div>
                        @endif
                        @if(filled($presentation['einvoice']['why'] ?? null))
                            <div class="text-muted mt-2">Why? {{ $presentation['einvoice']['why'] }}</div>
                        @endif
                        @if(filled($presentation['einvoice']['next_action'] ?? null))
                            <div class="text-muted">Next action: {{ $presentation['einvoice']['next_action'] }}</div>
                        @endif
                    </div>
                @endif
            @else
                <p class="mb-2 text-muted">{{ $presentation['eligibility_summary'] ?? 'GST invoice was not issued for this sale.' }}</p>
                @if(!empty($presentation['eligibility_errors']))
                    <ul class="small text-muted mb-2">
                        @foreach($presentation['eligibility_errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
                @if($presentation['can_issue_from_finance'] ?? false)
                    <form method="POST" action="{{ $presentation['issue_url'] }}" data-once-submit>
                        @csrf
                        <button class="btn btn-sm btn-outline-primary">Issue from Finance Hub</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
@endif
