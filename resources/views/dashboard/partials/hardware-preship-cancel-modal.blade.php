<div class="dashboard-hardware-preship-cancel card shadow d-none"
     data-hardware-preship-cancel-dialog
     hidden
     role="dialog"
     aria-modal="true"
     aria-labelledby="hardware-preship-cancel-title">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <h2 class="h6 mb-0" id="hardware-preship-cancel-title">Cancel order (pre-shipment)</h2>
            <button type="button" class="btn-close" data-hardware-preship-cancel-close aria-label="Close"></button>
        </div>
        <p class="small text-muted mb-2" data-hardware-preship-cancel-subtitle>
            Phase 2 State A+B only. Serials allocated, statutory invoice, or AWB/shipment evidence cannot be cancelled here.
        </p>
        <div class="alert alert-warning d-none py-2 small mb-2" data-hardware-preship-cancel-ineligible hidden></div>
        <div class="alert alert-danger d-none py-2 small mb-2" data-hardware-preship-cancel-split-tender hidden>
            <strong>Split tender (Cashfree + Wallet).</strong> Finance must manually review and execute each leg. Submission does not execute refunds.
        </div>
        <div class="card card-body mb-3 small d-none" data-hardware-preship-cancel-breakdown hidden>
            <h3 class="h6">Payment breakdown</h3>
            <dl class="row mb-0">
                <dt class="col-sm-5">Order total</dt>
                <dd class="col-sm-7" data-hardware-preship-order-total>—</dd>
                <dt class="col-sm-5">Cashfree / external</dt>
                <dd class="col-sm-7" data-hardware-preship-external-tender>—</dd>
                <dt class="col-sm-5">Wallet</dt>
                <dd class="col-sm-7" data-hardware-preship-wallet-tender>—</dd>
                <dt class="col-sm-5">Already refunded</dt>
                <dd class="col-sm-7" data-hardware-preship-already-refunded>—</dd>
                <dt class="col-sm-5">Maximum refundable</dt>
                <dd class="col-sm-7" data-hardware-preship-max-refundable>—</dd>
            </dl>
        </div>
        <div class="alert alert-secondary d-none small mb-2" data-hardware-preship-cancel-breakdown-error hidden></div>
        <form data-hardware-preship-cancel-form class="d-none" novalidate>
            <div class="mb-2">
                <label class="form-label" for="hardware-preship-cancellation-reason">Cancellation reason</label>
                <textarea id="hardware-preship-cancellation-reason"
                          name="cancellation_reason"
                          class="form-control"
                          rows="3"
                          required
                          minlength="10"
                          data-hardware-preship-cancellation-reason></textarea>
                <div class="invalid-feedback" data-hardware-preship-field-error="cancellation_reason"></div>
            </div>
            <fieldset class="mb-2">
                <legend class="form-label col-form-label pt-0">Refund preference</legend>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="refund_preference" id="hardware-preship-refund-wallet" value="wallet">
                    <label class="form-check-label" for="hardware-preship-refund-wallet">Wallet credit</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="refund_preference" id="hardware-preship-refund-opm" value="opm">
                    <label class="form-check-label" for="hardware-preship-refund-opm">Original payment method (OPM)</label>
                </div>
                <div class="invalid-feedback d-block" data-hardware-preship-field-error="refund_preference"></div>
            </fieldset>
            <div class="mb-3">
                <label class="form-label" for="hardware-preship-operator-notes">Notes (optional)</label>
                <textarea id="hardware-preship-operator-notes"
                          name="operator_notes"
                          class="form-control"
                          rows="2"
                          maxlength="500"
                          data-hardware-preship-operator-notes></textarea>
            </div>
            <input type="hidden" name="idempotency_key" data-hardware-preship-idempotency-key value="">
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-hardware-preship-cancel-close>Close</button>
                <button type="submit" class="btn btn-danger btn-sm" data-hardware-preship-cancel-submit disabled>
                    Submit cancellation
                </button>
            </div>
        </form>
        <div class="text-danger small d-none" data-hardware-preship-cancel-submit-error></div>
    </div>
</div>