<div class="modal fade"
     id="legacySearchConfirmModal"
     tabindex="-1"
     aria-labelledby="legacySearchConfirmModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5 mb-0" id="legacySearchConfirmModalLabel">Create Service Request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <p class="text-muted small mb-3">
                    Review the legacy order details and confirm how this service request should be logged.
                </p>

                <dl class="row small mb-3">
                    <dt class="col-sm-4 text-muted">Order ID</dt>
                    <dd class="col-sm-8 mb-1" data-legacy-confirm-order-id>—</dd>
                    <dt class="col-sm-4 text-muted">Customer name</dt>
                    <dd class="col-sm-8 mb-1" data-legacy-confirm-customer-name>—</dd>
                    <dt class="col-sm-4 text-muted">Phone</dt>
                    <dd class="col-sm-8 mb-1" data-legacy-confirm-mobile>—</dd>
                    <dt class="col-sm-4 text-muted">Email</dt>
                    <dd class="col-sm-8 mb-1" data-legacy-confirm-email>—</dd>
                    <dt class="col-sm-4 text-muted">Product / model</dt>
                    <dd class="col-sm-8 mb-1" data-legacy-confirm-product-model>—</dd>
                    <dt class="col-sm-4 text-muted">Serial number</dt>
                    <dd class="col-sm-8 mb-0" data-legacy-confirm-serial-number>—</dd>
                </dl>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="legacy_search_confirm_source" class="form-label">Source <span class="text-danger">*</span></label>
                        <select id="legacy_search_confirm_source"
                                class="form-select"
                                required>
                            <option value="" disabled selected>Select source</option>
                            @foreach(($enabledSources ?? collect()) as $sourceOption)
                                <option value="{{ $sourceOption->key }}">{{ $sourceOption->label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox"
                                   value="1"
                                   id="legacy_search_confirm_high_priority"
                                   class="form-check-input">
                            <label class="form-check-label" for="legacy_search_confirm_high_priority">
                                High Priority
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="legacy_search_confirm_notes" class="form-label">Comment / Issue Description <span class="text-danger">*</span></label>
                        <textarea id="legacy_search_confirm_notes"
                                  rows="3"
                                  class="form-control"
                                  required
                                  placeholder="Describe the issue or service request..."></textarea>
                    </div>
                </div>

                <div class="alert alert-danger py-2 small mb-0 mt-3 d-none"
                     id="legacy_search_confirm_error"
                     role="alert"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button"
                        class="btn btn-primary btn-sm"
                        data-legacy-search-confirm-submit>
                    Create Service Request
                </button>
            </div>
        </div>
    </div>
</div>
