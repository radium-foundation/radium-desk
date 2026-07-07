<div class="modal fade" id="legacyVerificationModal" tabindex="-1" aria-labelledby="legacyVerificationModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5 mb-0" id="legacyVerificationModalLabel">Legacy Customer Verification</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <div id="legacy-verification-customer-panel">
                    <p class="mb-3">Confirm before completing:</p>
                    <ul class="list-unstyled mb-3">
                        <li><i class="bi bi-check-circle text-success me-2"></i>Customer matched</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Device/serial verified</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Service eligibility checked</li>
                    </ul>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="legacy_verification_confirmed">
                        <label class="form-check-label" for="legacy_verification_confirmed">
                            I confirm legacy customer verification is complete.
                        </label>
                    </div>
                </div>

                <div id="legacy-verification-imported-panel" class="d-none">
                    <p class="mb-3">
                        Legacy imported order. Verify customer, serial, invoice and eligibility.
                    </p>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="legacy_import_fulfillment_confirmed">
                        <label class="form-check-label" for="legacy_import_fulfillment_confirmed">
                            Verified legacy order details
                        </label>
                    </div>
                </div>

                <div class="invalid-feedback d-block small" id="legacy-verification-error"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="legacy-verification-confirm-button" disabled>
                    Confirm &amp; Continue
                </button>
            </div>
        </div>
    </div>
</div>
