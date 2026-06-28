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
