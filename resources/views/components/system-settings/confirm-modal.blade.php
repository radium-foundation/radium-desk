<div class="modal fade"
     id="system-settings-confirm-modal"
     tabindex="-1"
     aria-labelledby="system-settings-confirm-title"
     aria-hidden="true"
     data-system-settings-confirm-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="system-settings-confirm-title">Confirm change</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p data-system-settings-confirm-message></p>
                <div class="system-settings-confirm-impact" data-system-settings-confirm-impact hidden>
                    <h3 class="system-settings-confirm-impact__title">Impact</h3>
                    <p data-system-settings-confirm-impact-text></p>
                    <h3 class="system-settings-confirm-impact__title">Affected modules</h3>
                    <ul data-system-settings-confirm-modules></ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" data-system-settings-confirm-accept>Confirm</button>
            </div>
        </div>
    </div>
</div>
