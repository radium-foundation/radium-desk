<div class="modal fade hardware-preship-cancel-modal"
     id="hardwarePreshipCancelModal"
     data-hardware-preship-cancel-dialog
     tabindex="-1"
     aria-labelledby="hardware-preship-cancel-title"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable hardware-preship-cancel-modal__dialog">
        <div class="modal-content">
            <div class="modal-header py-2 px-3">
                <div>
                    <h2 class="h6 mb-0" id="hardware-preship-cancel-title">Cancel order (pre-shipment)</h2>
                    <p class="small text-muted mb-0" data-hardware-preship-cancel-subtitle>
                        Phase 2 State A+B only. Serials allocated, statutory invoice, or AWB/shipment evidence cannot be cancelled here.
                    </p>
                </div>
                <button type="button"
                        class="btn-close"
                        data-hardware-preship-cancel-close
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <div class="modal-body hardware-preship-cancel-modal__body py-2 px-3">
                <div class="alert alert-warning d-none py-2 small mb-2"
                     data-hardware-preship-cancel-ineligible
                     role="alert"
                     hidden></div>
                <div class="alert alert-danger d-none py-2 small mb-2"
                     data-hardware-preship-cancel-split-tender
                     role="alert"
                     hidden>
                    <strong>Split tender (Cashfree + Wallet).</strong> Finance must manually review and execute each leg. Submission does not execute refunds.
                </div>
                <div class="card card-body mb-2 py-2 px-2 small d-none"
                     data-hardware-preship-cancel-breakdown
                     hidden>
                    <h3 class="h6 mb-2">Payment breakdown</h3>
                    <dl class="row mb-0 g-1 hardware-preship-cancel-modal__breakdown">
                        <dt class="col-6">Order total</dt>
                        <dd class="col-6 text-end mb-1" data-hardware-preship-order-total>—</dd>
                        <dt class="col-6">Cashfree / external</dt>
                        <dd class="col-6 text-end mb-1" data-hardware-preship-external-tender>—</dd>
                        <dt class="col-6">Wallet</dt>
                        <dd class="col-6 text-end mb-1" data-hardware-preship-wallet-tender>—</dd>
                        <dt class="col-6">Already refunded</dt>
                        <dd class="col-6 text-end mb-1" data-hardware-preship-already-refunded>—</dd>
                        <dt class="col-6">Maximum refundable</dt>
                        <dd class="col-6 text-end mb-0" data-hardware-preship-max-refundable>—</dd>
                    </dl>
                </div>
                <div class="alert alert-secondary d-none small mb-2 py-2"
                     data-hardware-preship-cancel-breakdown-error
                     role="alert"
                     hidden></div>
                <form data-hardware-preship-cancel-form class="d-none" novalidate>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="hardware-preship-cancellation-reason">Cancellation reason</label>
                        <textarea id="hardware-preship-cancellation-reason"
                                  name="cancellation_reason"
                                  class="form-control form-control-sm"
                                  rows="2"
                                  required
                                  minlength="10"
                                  data-hardware-preship-cancellation-reason></textarea>
                        <div class="text-danger small mt-1"
                             data-hardware-preship-field-error="cancellation_reason"
                             role="alert"></div>
                    </div>
                    <fieldset class="mb-2">
                        <legend class="form-label small col-form-label pt-0 mb-1">Refund preference</legend>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="refund_preference" id="hardware-preship-refund-wallet" value="wallet">
                            <label class="form-check-label small" for="hardware-preship-refund-wallet">Wallet credit</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="refund_preference" id="hardware-preship-refund-opm" value="opm">
                            <label class="form-check-label small" for="hardware-preship-refund-opm">Original payment method (OPM)</label>
                        </div>
                        <div class="text-danger small mt-1"
                             data-hardware-preship-field-error="refund_preference"
                             role="alert"></div>
                    </fieldset>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="hardware-preship-operator-notes">Notes (optional)</label>
                        <textarea id="hardware-preship-operator-notes"
                                  name="operator_notes"
                                  class="form-control form-control-sm"
                                  rows="2"
                                  maxlength="500"
                                  data-hardware-preship-operator-notes></textarea>
                        <div class="text-danger small mt-1"
                             data-hardware-preship-field-error="operator_notes"
                             role="alert"></div>
                    </div>
                    <input type="hidden" name="idempotency_key" data-hardware-preship-idempotency-key value="">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button"
                                class="btn btn-outline-secondary btn-sm"
                                data-hardware-preship-cancel-close
                                data-bs-dismiss="modal">
                            Close
                        </button>
                        <button type="submit" class="btn btn-danger btn-sm" data-hardware-preship-cancel-submit disabled>
                            Submit cancellation
                        </button>
                    </div>
                </form>
                <div class="alert alert-danger d-none small mb-0 py-2"
                     data-hardware-preship-cancel-submit-error
                     role="alert"
                     hidden></div>
            </div>
        </div>
    </div>
</div>