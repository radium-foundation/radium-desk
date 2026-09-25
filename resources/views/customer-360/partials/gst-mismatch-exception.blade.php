@if(is_array($gstMismatchException ?? null))
    <section class="customer-360-section"
             data-customer-360-section="gst-mismatch-exception"
             aria-labelledby="customer-360-gst-mismatch-heading">
        <h3 class="customer-360-section-title" id="customer-360-gst-mismatch-heading">
            GST Mismatch — Statutory Invoice Pending
        </h3>

        <div class="alert alert-warning mb-3" role="status">
            {{ $gstMismatchException['status_label'] }}
        </div>

        <dl class="c360-statutory-invoice-meta">
            <div>
                <dt>Order ID</dt>
                <dd>{{ $gstMismatchException['order_id'] }}</dd>
            </div>
            <div>
                <dt>Payment status</dt>
                <dd>{{ $gstMismatchException['payment_status'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>GSTIN submitted</dt>
                <dd>{{ $gstMismatchException['original_buyer_gstin'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>GSTIN state</dt>
                <dd>
                    {{ $gstMismatchException['original_gstin_state_name'] ?? '—' }}
                    @if(filled($gstMismatchException['original_gstin_state_code'] ?? null))
                        ({{ $gstMismatchException['original_gstin_state_code'] }})
                    @endif
                </dd>
            </div>
            <div>
                <dt>Billing state</dt>
                <dd>{{ $gstMismatchException['original_billing_state'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>Place of supply</dt>
                <dd>{{ $gstMismatchException['original_place_of_supply_state'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>PIN</dt>
                <dd>{{ $gstMismatchException['original_billing_pincode'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>Validation failure</dt>
                <dd>{{ $gstMismatchException['validation_reason'] }}</dd>
            </div>
            <div>
                <dt>Customer email</dt>
                <dd>{{ $gstMismatchException['customer_email'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>Email sent</dt>
                <dd>{{ $gstMismatchException['customer_email_sent_at_label'] ?? 'Not sent' }}</dd>
            </div>
            <div>
                <dt>Response deadline</dt>
                <dd>{{ $gstMismatchException['response_deadline_label'] ?? '—' }}</dd>
            </div>
            @if(filled($gstMismatchException['corrected_buyer_gstin'] ?? null))
                <div>
                    <dt>Corrected GSTIN</dt>
                    <dd>{{ $gstMismatchException['corrected_buyer_gstin'] }}</dd>
                </div>
                <div>
                    <dt>Corrected billing state</dt>
                    <dd>{{ $gstMismatchException['corrected_billing_state'] }}</dd>
                </div>
            @endif
            @if(filled($gstMismatchException['invoice_number'] ?? null))
                <div>
                    <dt>Invoice number</dt>
                    <dd>{{ $gstMismatchException['invoice_number'] }}</dd>
                </div>
            @endif
            @if(filled($gstMismatchException['fallback_reason'] ?? null))
                <div>
                    <dt>Fallback reason</dt>
                    <dd>{{ $gstMismatchException['fallback_reason'] }}</dd>
                </div>
            @endif
        </dl>

        @if($gstMismatchException['can_record_correction'] ?? false)
            <form method="post"
                  action="{{ $gstMismatchException['correction_url'] }}"
                  class="mt-3"
                  data-c360-gst-mismatch-correction-form>
                @csrf
                <h4 class="h6">Record verified customer correction</h4>
                <div class="mb-2">
                    <label class="form-label" for="gst-mismatch-buyer-gstin">Corrected GSTIN</label>
                    <input id="gst-mismatch-buyer-gstin"
                           name="buyer_gstin"
                           class="form-control"
                           maxlength="15"
                           required>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="gst-mismatch-billing-state">Corrected billing state</label>
                    <input id="gst-mismatch-billing-state"
                           name="billing_state"
                           class="form-control"
                           required>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="gst-mismatch-pos-state">Corrected place of supply</label>
                    <input id="gst-mismatch-pos-state"
                           name="place_of_supply_state"
                           class="form-control">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="gst-mismatch-pincode">Billing PIN</label>
                    <input id="gst-mismatch-pincode"
                           name="billing_pincode"
                           class="form-control">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="gst-mismatch-verification-notes">Verification notes</label>
                    <textarea id="gst-mismatch-verification-notes"
                              name="verification_notes"
                              class="form-control"
                              rows="3"
                              required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Save correction and issue B2B invoice</button>
            </form>
        @endif
    </section>
@endif
