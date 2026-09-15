<div class="modal fade"
     id="historicalOrderSummaryModal"
     tabindex="-1"
     aria-labelledby="historicalOrderSummaryModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h5 mb-0" id="historicalOrderSummaryModalLabel">Historical Order</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <div data-historical-order-summary-body></div>
                <div class="alert alert-danger py-2 small mb-0 mt-3 d-none"
                     data-historical-order-summary-error
                     role="alert"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
