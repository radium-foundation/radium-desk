@php
    use App\Enums\CustomerPreferredRefundMethod;
    use App\Models\RefundRequest;

    $order = $incident->order;
    $refundModel = $refund instanceof RefundRequest ? $refund : new RefundRequest;
    $maximumRefundable = $calculation?->maximumRefundable;
    $preferredMethodValue = old(
        'customer_preferred_method',
        $formPayload['customer_preferred_method'] ?? $refundModel->customer_preferred_method?->value ?? CustomerPreferredRefundMethod::Opm->value,
    );
    $amountValue = old('amount');

    if ($amountValue === null) {
        if (array_key_exists('amount', $formPayload) && $formPayload['amount'] !== '' && $formPayload['amount'] !== null) {
            $amountValue = $formPayload['amount'];
        } elseif ($maximumRefundable !== null) {
            $amountValue = number_format((float) $maximumRefundable, 2, '.', '');
        }
    }

    $reasonValue = old('reason', $formPayload['reason'] ?? $refundModel->reason);
    $notifyEmailChecked = old(
        'notify_email',
        array_key_exists('notify_email', $formPayload ?? [])
            ? filter_var($formPayload['notify_email'], FILTER_VALIDATE_BOOLEAN)
            : in_array('email', $formPayload['communication_channels'] ?? ['email'], true),
    );
    $notifyWhatsappChecked = old(
        'notify_whatsapp',
        array_key_exists('notify_whatsapp', $formPayload ?? [])
            ? filter_var($formPayload['notify_whatsapp'], FILTER_VALIDATE_BOOLEAN)
            : in_array('whatsapp', $formPayload['communication_channels'] ?? ['whatsapp'], true),
    );
    $paymentGateway = filled($order?->payment_method)
        ? $order->payment_method
        : ($order?->isCashfreeVerified() ? 'Cashfree' : null);
    $paymentStatus = $order?->isCashfreeVerified()
        ? 'Verified'
        : (filled($order?->transaction_id) ? 'Recorded' : null);
    $hasPaymentSummary = $paymentGateway !== null
        || $paymentStatus !== null
        || $order?->payment_date !== null
        || filled($order?->transaction_id);
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="refund-request"
      class="workspace-note-dialog c360-dialog refund-request-dialog">
    @csrf
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <x-c360.dialog-header
        icon="↩"
        title="Refund Request"
        subtitle="Submit a refund request for this order without leaving Customer360." />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        @if($order === null)
            <div class="alert alert-warning mb-0" role="alert">
                This service case has no linked order. Link an order before requesting a refund.
            </div>
        @else
            <x-c360.section-card title="Context" heading-id="refund-request-context-heading" class="mb-3">
                <dl class="request-serial-dialog-dl mb-0">
                    <div class="request-serial-dialog-dl-row">
                        <dt>Customer Name</dt>
                        <dd>{{ filled($order->customer_name) ? $order->customer_name : 'Not Available' }}</dd>
                    </div>
                    <div class="request-serial-dialog-dl-row">
                        <dt>Order ID</dt>
                        <dd>{{ $order->order_id }}</dd>
                    </div>
                    <div class="request-serial-dialog-dl-row">
                        <dt>Case / Incident</dt>
                        <dd>{{ $incident->reference_no }} — {{ $incident->title }}</dd>
                    </div>
                </dl>
            </x-c360.section-card>

            <x-c360.section-card title="Refund Summary" heading-id="refund-request-summary-heading" class="mb-3">
                <dl class="request-serial-dialog-dl mb-0">
                    <div class="request-serial-dialog-dl-row">
                        <dt>Paid Amount</dt>
                        <dd>₹{{ number_format($calculation?->totalPaidAmount ?? 0, 2) }}</dd>
                    </div>
                    <div class="request-serial-dialog-dl-row">
                        <dt>Already Refunded</dt>
                        <dd>₹{{ number_format($calculation?->alreadyRefundedAmount ?? 0, 2) }}</dd>
                    </div>
                    <div class="request-serial-dialog-dl-row">
                        <dt>Maximum Refundable</dt>
                        <dd class="fw-semibold">₹{{ number_format($calculation?->maximumRefundable ?? 0, 2) }}</dd>
                    </div>
                </dl>
            </x-c360.section-card>

            @if($hasPaymentSummary)
                <x-c360.section-card title="Payment Summary" heading-id="refund-request-payment-summary-heading" class="mb-3">
                    <dl class="request-serial-dialog-dl mb-0">
                        @if($paymentGateway !== null)
                            <div class="request-serial-dialog-dl-row">
                                <dt>Payment Gateway</dt>
                                <dd>{{ $paymentGateway }}</dd>
                            </div>
                        @endif
                        @if($paymentStatus !== null)
                            <div class="request-serial-dialog-dl-row">
                                <dt>Payment Status</dt>
                                <dd>{{ $paymentStatus }}</dd>
                            </div>
                        @endif
                        @if($order->payment_date !== null)
                            <div class="request-serial-dialog-dl-row">
                                <dt>Payment Date</dt>
                                <dd>{{ display_app_datetime($order->payment_date) }}</dd>
                            </div>
                        @endif
                        @if(filled($order->transaction_id))
                            <div class="request-serial-dialog-dl-row">
                                <dt>Payment Reference</dt>
                                <dd>{{ $order->transaction_id }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-c360.section-card>
            @endif

            <x-c360.section-card title="Refund Details" heading-id="refund-request-details-heading" class="mb-3">
                <div class="c360-dialog-form-grid">
                    <div class="c360-dialog-field">
                        <label for="refund-request-preferred-method" class="form-label">
                            Preferred Refund Method <span class="text-danger">*</span>
                        </label>
                        <select name="customer_preferred_method"
                                id="refund-request-preferred-method"
                                class="form-select @error('customer_preferred_method') is-invalid @enderror"
                                required>
                            @foreach($preferredMethods as $method)
                                <option value="{{ $method->value }}" @selected($preferredMethodValue === $method->value)>
                                    {{ $method->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('customer_preferred_method')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Advisory only — Ops chooses the actual payout method at approval.</div>
                    </div>

                    <div class="c360-dialog-field">
                        <label for="refund-request-amount" class="form-label">Refund Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number"
                                   name="amount"
                                   id="refund-request-amount"
                                   step="0.01"
                                   min="0.01"
                                   class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ $amountValue }}"
                                   placeholder="Defaults to maximum refundable">
                        </div>
                        @error('amount')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        @error('refund_amount')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="c360-dialog-field c360-dialog-field--full">
                        <label for="refund-request-reason" class="form-label">
                            Reason <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason"
                                  id="refund-request-reason"
                                  rows="4"
                                  class="form-control @error('reason') is-invalid @enderror"
                                  placeholder="Describe why this refund is being requested..."
                                  required>{{ $reasonValue }}</textarea>
                        @error('reason')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-c360.section-card>

            <x-c360.section-card title="Customer Communication" heading-id="refund-request-communication-heading">
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               name="notify_email"
                               id="refund-request-notify-email"
                               value="1"
                               @checked($notifyEmailChecked)>
                        <label class="form-check-label" for="refund-request-notify-email">Email Customer</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               name="notify_whatsapp"
                               id="refund-request-notify-whatsapp"
                               value="1"
                               @checked($notifyWhatsappChecked)>
                        <label class="form-check-label" for="refund-request-notify-whatsapp">WhatsApp Customer</label>
                    </div>
                </div>
                <div class="form-text">Sent automatically after the refund is marked completed.</div>
            </x-c360.section-card>
        @endif
    </div>

    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        @if($order !== null)
            <button type="submit" class="btn c360-dialog-btn-primary">Submit Refund</button>
        @endif
    </x-c360.modal-footer>
</form>
