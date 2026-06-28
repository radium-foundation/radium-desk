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
                <p class="small mb-3 mb-lg-4">
                    You are about to permanently assign this Serial Number.
                </p>
                <p class="small mb-3">
                    After confirmation, it cannot be changed by Agents, Supervisors, or Admins.
                </p>
                <p class="small text-muted mb-3">Only Super Admin can unlock it.</p>
                <div class="serial-number-confirm-display">
                    <span class="badge rounded-pill text-bg-light border font-monospace serial-number-confirm-badge"
                          id="serial_number_confirm_value"
                          aria-live="polite"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-serial-confirm-back>Back</button>
                <button type="button" class="btn btn-primary btn-sm" data-serial-confirm-lock>Confirm &amp; Lock</button>
            </div>
        </div>
    </div>
</div>
