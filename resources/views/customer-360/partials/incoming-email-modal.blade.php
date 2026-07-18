<div class="modal fade"
     id="incomingEmailModal"
     tabindex="-1"
     aria-labelledby="incomingEmailModalLabel"
     aria-hidden="true"
     data-incoming-email-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h2 class="modal-title h5 mb-1" id="incomingEmailModalLabel" data-incoming-email-modal-subject>
                        Email
                    </h2>
                    <p class="small text-muted mb-0" data-incoming-email-modal-meta></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <div class="d-flex justify-content-center py-4" data-incoming-email-modal-loading hidden>
                    <div class="spinner-border text-primary" role="status" aria-label="Loading email"></div>
                </div>
                <div class="alert alert-danger" data-incoming-email-modal-error hidden></div>
                <div class="c360-incoming-email-body" data-incoming-email-modal-body hidden></div>
                <ul class="list-unstyled mb-0 mt-3" data-incoming-email-modal-attachments hidden></ul>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
