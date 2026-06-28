<div class="modal fade"
     id="serialNumberModal"
     tabindex="-1"
     aria-labelledby="serialNumberModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="serialNumberModalLabel">Enter Serial Number</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div>
                    <label for="dashboard_serial_number" class="form-label">Serial Number</label>
                    <input type="text"
                           class="form-control"
                           id="dashboard_serial_number"
                           maxlength="100"
                           autocomplete="off"
                           required
                           aria-describedby="dashboard_serial_number_error">
                    <div id="dashboard_serial_number_error" class="invalid-feedback"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" data-serial-modal-save>Save</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade"
     id="serialNumberConfirmModal"
     tabindex="-1"
     aria-labelledby="serialNumberConfirmModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="serialNumberConfirmModalLabel">Confirm Serial Number</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-3">
                    You are about to permanently assign this Serial Number.
                </p>
                <p class="small mb-3">
                    After confirmation it cannot be edited by:
                </p>
                <ul class="small mb-3 ps-3">
                    <li>Agent</li>
                    <li>Supervisor</li>
                    <li>Admin</li>
                </ul>
                <p class="small text-muted mb-2">Only Super Admin can unlock it later.</p>
                <div class="border rounded bg-light px-3 py-2">
                    <div class="small text-muted mb-1">Serial Number:</div>
                    <div class="fw-semibold font-monospace text-break" id="serial_number_confirm_value" aria-live="polite"></div>
                </div>
                <div id="serial_number_confirm_error" class="invalid-feedback d-block small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-serial-confirm-back>Back</button>
                <button type="button" class="btn btn-primary btn-sm" data-serial-confirm-lock>Confirm &amp; Lock</button>
            </div>
        </div>
    </div>
</div>
