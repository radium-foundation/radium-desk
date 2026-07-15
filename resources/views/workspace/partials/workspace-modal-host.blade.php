<div class="modal fade workspace-modal"
     id="workspaceModal"
     tabindex="-1"
     aria-hidden="true"
     data-workspace-modal-host>
    <div class="workspace-discard-confirm"
         data-workspace-discard-confirm
         hidden
         role="alertdialog"
         aria-modal="true"
         aria-labelledby="workspace-discard-confirm-title">
        <div class="workspace-discard-confirm__panel">
            <h3 id="workspace-discard-confirm-title" class="workspace-discard-confirm__title">Discard changes?</h3>
            <p class="workspace-discard-confirm__text">Your unsaved changes will be lost.</p>
            <div class="workspace-discard-confirm__actions">
                <button type="button" class="btn c360-dialog-btn-ghost" data-workspace-discard-cancel>Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" data-workspace-discard-apply>Discard</button>
            </div>
        </div>
    </div>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" data-workspace-modal-content></div>
    </div>
</div>
