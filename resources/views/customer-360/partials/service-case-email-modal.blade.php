<div class="modal fade"
     id="serviceCaseEmailModal"
     tabindex="-1"
     aria-labelledby="serviceCaseEmailModalLabel"
     aria-hidden="true"
     data-service-case-email-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content c360-email-workspace">
            <div class="modal-header border-0 pb-2">
                <div class="flex-grow-1 pe-3">
                    <h2 class="modal-title h5 mb-2" id="serviceCaseEmailModalLabel">
                        Email
                    </h2>
                    <div class="c360-channel-meta-header" data-sc-email-channel-meta>
                        <div class="c360-channel-meta-grid" role="list">
                            <div class="c360-channel-meta-item" role="listitem">
                                <span class="c360-channel-meta-key">Customer</span>
                                <span class="c360-channel-meta-value" data-sc-email-meta-customer>Customer</span>
                            </div>
                            <div class="c360-channel-meta-item" role="listitem">
                                <span class="c360-channel-meta-key">Owner</span>
                                <span class="c360-channel-meta-value" data-sc-email-meta-owner>Unassigned</span>
                            </div>
                            <div class="c360-channel-meta-item" role="listitem">
                                <span class="c360-channel-meta-key">Last inbound</span>
                                <span class="c360-channel-meta-value" data-sc-email-last-in>—</span>
                            </div>
                            <div class="c360-channel-meta-item" role="listitem">
                                <span class="c360-channel-meta-key">Last outbound</span>
                                <span class="c360-channel-meta-value" data-sc-email-last-out>—</span>
                            </div>
                        </div>
                    </div>
                    <p class="small text-muted mb-0 mt-1" data-sc-email-modal-subtitle hidden>
                        Service Case conversation
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <div class="c360-email-loading" data-sc-email-loading hidden>
                    <div class="c360-email-skeleton" aria-hidden="true"></div>
                    <div class="c360-email-skeleton c360-email-skeleton--short" aria-hidden="true"></div>
                    <div class="spinner-border spinner-border-sm text-primary mt-2" role="status" aria-label="Loading email conversation"></div>
                </div>
                <div class="alert alert-danger" data-sc-email-error hidden></div>
                <div class="c360-email-thread" data-sc-email-thread hidden>
                    <button type="button"
                            class="btn btn-link btn-sm px-0 align-self-center"
                            data-sc-email-load-older
                            hidden>
                        Load older messages
                    </button>
                    <div data-sc-email-thread-list></div>
                </div>
                <div class="text-muted small text-center py-4" data-sc-email-empty hidden>
                    No emails on this Service Case yet.
                </div>

                <div class="border-top mt-3 pt-3" data-sc-email-composer hidden>
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <h3 class="h6 mb-0">Reply</h3>
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                data-sc-email-reply-cancel
                                hidden>
                            Cancel
                        </button>
                    </div>
                    <p class="small text-muted mb-2" data-sc-email-reply-hint hidden></p>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="serviceCaseEmailSubject">Subject</label>
                        <input id="serviceCaseEmailSubject"
                               type="text"
                               class="form-control form-control-sm"
                               data-sc-email-subject
                               maxlength="998">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="serviceCaseEmailBody">Message</label>
                        <textarea id="serviceCaseEmailBody"
                                  class="form-control form-control-sm"
                                  rows="5"
                                  data-sc-email-body
                                  placeholder="Write a plain message…"></textarea>
                    </div>
                    <div class="alert alert-danger py-2 small mb-0" data-sc-email-send-error hidden></div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button"
                        class="btn btn-sm btn-outline-primary me-auto"
                        data-sc-email-reply-toggle
                        hidden>
                    Reply
                </button>
                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-sc-email-send
                        hidden>
                    Send
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
