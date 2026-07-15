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
    $paymentStatusChipType = match ($paymentStatus) {
        'Verified', 'Recorded', 'Paid' => 'pass',
        'Pending' => 'warning',
        'Failed' => 'fail',
        default => 'info',
    };
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="refund-request"
      class="workspace-note-dialog workspace-dialog-shell c360-dialog refund-request-dialog">
    @csrf
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <x-c360.dialog-header icon="↩" title="Refund Request" />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body workspace-dialog-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-2" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        @if($order === null)
            <div class="alert alert-warning mb-0 py-2 px-3 small" role="alert">
                This service case has no linked order. Link an order before requesting a refund.
            </div>
        @else
            <x-c360.workspace-context-strip class="workspace-context-strip--minimal workspace-dialog-block">
                <x-c360.workspace-context-item icon="👤">
                    {{ filled($order->customer_name) ? $order->customer_name : 'Not Available' }}
                </x-c360.workspace-context-item>
                <x-c360.workspace-context-item>
                    {{ $order->order_id }}
                </x-c360.workspace-context-item>
                <x-c360.workspace-context-item>
                    {{ $incident->reference_no }}
                </x-c360.workspace-context-item>
            </x-c360.workspace-context-strip>

            <x-c360.workspace-dialog-stack class="workspace-dialog-block">
                <x-c360.workspace-hero-kpi
                    :amount="'₹'.number_format($calculation?->maximumRefundable ?? 0, 2)"
                    caption="Maximum Refundable">
                    <x-slot:secondary>
                        <x-c360.workspace-kpi-secondary>
                            <x-c360.workspace-kpi-secondary-item label="Paid">
                                ₹{{ number_format($calculation?->totalPaidAmount ?? 0, 2) }}
                            </x-c360.workspace-kpi-secondary-item>
                            <x-c360.workspace-kpi-secondary-item label="Refunded">
                                ₹{{ number_format($calculation?->alreadyRefundedAmount ?? 0, 2) }}
                            </x-c360.workspace-kpi-secondary-item>
                        </x-c360.workspace-kpi-secondary>
                    </x-slot:secondary>
                    <x-slot:meta>
                        <x-c360.workspace-kpi-meta>
                            @if($paymentStatus !== null)
                                <x-c360.status-chip :type="$paymentStatusChipType" :label="$paymentStatus" />
                            @endif
                            @if($paymentGateway !== null)
                                <span class="workspace-kpi-meta__text">{{ $paymentGateway }}</span>
                            @endif
                            @if($order->payment_date !== null)
                                <span class="workspace-kpi-meta__text">{{ display_app_datetime($order->payment_date) }}</span>
                            @endif
                            @if(filled($order->transaction_id))
                                <span class="workspace-kpi-meta__text">
                                    Payment Reference {{ $order->transaction_id }}
                                </span>
                            @endif
                        </x-c360.workspace-kpi-meta>
                    </x-slot:meta>
                </x-c360.workspace-hero-kpi>
            </x-c360.workspace-dialog-stack>

            <x-c360.workspace-dialog-stack class="workspace-dialog-stack--form workspace-dialog-block workspace-dialog-block--last">
                <div class="workspace-form-row workspace-form-row--2">
                    <div class="workspace-form-field">
                        <label for="refund-request-preferred-method" class="workspace-form-label">
                            Method <span class="text-danger">*</span>
                            <x-c360.workspace-field-hint text="Advisory only — Ops chooses the actual payout method at approval." />
                        </label>
                        <select name="customer_preferred_method"
                                id="refund-request-preferred-method"
                                class="form-select form-select-sm @error('customer_preferred_method') is-invalid @enderror"
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
                    </div>

                    <div class="workspace-form-field">
                        <label for="refund-request-amount" class="workspace-form-label">Amount</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">₹</span>
                            <input type="number"
                                   name="amount"
                                   id="refund-request-amount"
                                   step="0.01"
                                   min="0.01"
                                   class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ $amountValue }}"
                                   placeholder="Max refundable">
                        </div>
                        @error('amount')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        @error('refund_amount')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="workspace-form-field workspace-form-field--full">
                    <label for="refund-request-reason" class="workspace-form-label">
                        Reason <span class="text-danger">*</span>
                    </label>
                    <textarea name="reason"
                              id="refund-request-reason"
                              rows="3"
                              class="form-control form-control-sm workspace-form-textarea--compact @error('reason') is-invalid @enderror"
                              placeholder="Why is this refund being requested?"
                              required>{{ $reasonValue }}</textarea>
                    @error('reason')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="workspace-inline-checks workspace-inline-checks--titled">
                    <span class="workspace-inline-checks__label">Notify</span>
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input"
                               type="checkbox"
                               name="notify_email"
                               id="refund-request-notify-email"
                               value="1"
                               @checked($notifyEmailChecked)>
                        <label class="form-check-label" for="refund-request-notify-email">Email</label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input"
                               type="checkbox"
                               name="notify_whatsapp"
                               id="refund-request-notify-whatsapp"
                               value="1"
                               @checked($notifyWhatsappChecked)>
                        <label class="form-check-label" for="refund-request-notify-whatsapp">WhatsApp</label>
                    </div>
                    <x-c360.workspace-field-hint text="Sent automatically after the refund is marked completed." />
                </div>
            </x-c360.workspace-dialog-stack>
        @endif
    </div>

    <x-c360.modal-footer class="workspace-dialog-footer">
        <button type="button" class="btn btn-sm c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        @if($order !== null)
            <button type="submit" class="btn btn-sm c360-dialog-btn-primary">Submit Refund</button>
        @endif
    </x-c360.modal-footer>
</form>
