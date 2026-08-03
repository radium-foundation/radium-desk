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

                <div class="border-top mt-3 pt-3" data-incoming-email-reply-panel hidden>
                    <h3 class="h6 mb-2">Reply</h3>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="incomingEmailReplyTemplate">Template</label>
                        <select id="incomingEmailReplyTemplate"
                                class="form-select form-select-sm"
                                data-incoming-email-reply-template></select>
                        <div class="form-text small">Suggested / Personal / Blank. Greeting and signature are automatic and editable.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="incomingEmailReplySubject">Subject</label>
                        <input id="incomingEmailReplySubject"
                               type="text"
                               class="form-control form-control-sm"
                               data-incoming-email-reply-subject
                               maxlength="998">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="incomingEmailReplyBody">Message</label>
                        <textarea id="incomingEmailReplyBody"
                                  class="form-control form-control-sm"
                                  rows="8"
                                  data-incoming-email-reply-body></textarea>
                    </div>
                    <div class="alert alert-danger py-2 small mb-2" data-incoming-email-reply-error hidden></div>
                    <div class="alert alert-success py-2 small mb-0" data-incoming-email-reply-success hidden></div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button"
                        class="btn btn-sm btn-outline-primary me-auto"
                        data-incoming-email-reply-toggle
                        hidden>
                    Reply
                </button>
                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-incoming-email-reply-send
                        hidden>
                    Send
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
