<div
    class="offcanvas offcanvas-end"
    tabindex="-1"
    id="automationHealthDetailDrawer"
    aria-labelledby="automationHealthDetailDrawerLabel"
    data-automation-health-drawer
>
    <div class="offcanvas-header border-bottom">
        <h2 class="offcanvas-title h5 mb-0" id="automationHealthDetailDrawerLabel">Execution Detail</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div data-automation-health-drawer-loading class="text-muted small d-none">Loading execution detail…</div>
        <div data-automation-health-drawer-error class="alert alert-danger small d-none" role="alert"></div>
        <div data-automation-health-drawer-content class="d-none">
            <dl class="row small mb-0">
                <dt class="col-4 text-muted">Policy</dt>
                <dd class="col-8 mb-2" data-field="policy_label"></dd>
                <dt class="col-4 text-muted">Action</dt>
                <dd class="col-8 mb-2" data-field="action_label"></dd>
                <dt class="col-4 text-muted">Subject</dt>
                <dd class="col-8 mb-2" data-field="subject"></dd>
                <dt class="col-4 text-muted">Metadata</dt>
                <dd class="col-8 mb-2" data-field="metadata_summary"></dd>
                <dt class="col-4 text-muted">Started</dt>
                <dd class="col-8 mb-2" data-field="started_at_display"></dd>
                <dt class="col-4 text-muted">Finished</dt>
                <dd class="col-8 mb-2" data-field="completed_at_display"></dd>
                <dt class="col-4 text-muted">Duration</dt>
                <dd class="col-8 mb-2" data-field="duration_display"></dd>
                <dt class="col-4 text-muted">Result</dt>
                <dd class="col-8 mb-2" data-field="status_label"></dd>
                <dt class="col-4 text-muted">Triggered By</dt>
                <dd class="col-8 mb-2" data-field="triggered_by"></dd>
                <dt class="col-4 text-muted">Retry</dt>
                <dd class="col-8 mb-2" data-field="retry_status"></dd>
                <dt class="col-4 text-muted">Error</dt>
                <dd class="col-8 mb-3 text-danger" data-field="error_message"></dd>
            </dl>

            <div class="accordion" id="automationHealthMetadataAccordion">
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button
                            class="accordion-button collapsed py-2 small"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#automationHealthMetadataCollapse"
                            aria-expanded="false"
                            aria-controls="automationHealthMetadataCollapse"
                        >
                            Raw metadata
                        </button>
                    </h3>
                    <div id="automationHealthMetadataCollapse" class="accordion-collapse collapse" data-bs-parent="#automationHealthMetadataAccordion">
                        <div class="accordion-body p-2">
                            <pre class="small mb-0 bg-light p-2 rounded" data-field="metadata_raw"></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
