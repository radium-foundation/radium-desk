@props([
    'order',
])

@can('checkOemWarranty', $order)
    <div
        class="oem-warranty-check mt-2"
        data-oem-warranty-check
        data-order-id="{{ $order->id }}"
        data-serial-number="{{ $order->serial_number }}"
        data-context-url="{{ route('orders.oem-warranty.context', $order) }}"
        data-start-url="{{ route('orders.oem-warranty.sessions.start', $order) }}"
        data-manual-url="{{ route('orders.oem-warranty.manual', $order) }}"
        data-draft-api-url="{{ url('/api/v1/oem-warranty/sessions/draft') }}"
    >
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-oem-warranty-refresh
                title="Check OEM warranty / RD validity"
                aria-label="Check OEM warranty or RD validity"
            >
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                <span class="visually-hidden">Check OEM warranty</span>
            </button>
            <span class="small text-muted" data-oem-warranty-status-label>Not checked</span>
        </div>
        <div class="small" data-oem-warranty-summary></div>
    </div>

    <dialog class="oem-warranty-dialog" data-oem-warranty-dialog>
        <form method="dialog" class="p-3" style="min-width: min(480px, 92vw);">
            <h3 class="h6 mb-3">OEM warranty / RD check</h3>
            <p class="small text-muted mb-2" data-oem-warranty-dialog-help>
                Desk opens the official provider portal in your browser. Complete any CAPTCHA or verification, then enter or review results before saving.
                Automated browser extraction is off by default; captured values are staff-attested, not vendor-authorized.
            </p>
            <div class="mb-2" data-oem-warranty-provider-row hidden>
                <label class="form-label">Provider</label>
                <select class="form-select form-select-sm" data-oem-warranty-provider-select>
                    <option value="mantratec">MantraTec</option>
                    <option value="morpho">Morpho / IDEMIA</option>
                </select>
            </div>
            <p class="small mb-3" data-oem-warranty-provider-label></p>
            <pre class="small bg-light border rounded p-2 mb-3" data-oem-warranty-draft-preview style="white-space: pre-wrap; max-height: 220px; overflow: auto;"></pre>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-oem-warranty-manual-toggle>
                    Enter results manually
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-oem-warranty-cancel>
                    Cancel lookup
                </button>
                <button type="submit" class="btn btn-sm btn-primary" data-oem-warranty-confirm>
                    Confirm and save
                </button>
            </div>
            <div class="mt-3 border-top pt-3" data-oem-warranty-manual-form hidden>
                <p class="small text-muted">Manual entry is recorded separately from extension capture.</p>
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label">Outcome</label>
                        <select class="form-select form-select-sm" data-oem-manual-outcome>
                            <option value="found">Found</option>
                            <option value="not_found">No record</option>
                            <option value="inconclusive">Inconclusive</option>
                        </select>
                    </div>
                    <div class="col-6" data-oem-manual-mantratec>
                        <label class="form-label">Warranty expiry</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-warranty-expiry placeholder="YYYY-MM-DD">
                    </div>
                    <div class="col-6" data-oem-manual-mantratec>
                        <label class="form-label">RD expiry</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-rd-expiry placeholder="YYYY-MM-DD">
                    </div>
                    <div class="col-12" data-oem-manual-mantratec>
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-model>
                    </div>
                    <div class="col-12" data-oem-manual-morpho hidden>
                        <label class="form-label">Order number</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-order-number>
                    </div>
                    <div class="col-6" data-oem-manual-morpho hidden>
                        <label class="form-label">Whitelisting date</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-whitelisting-date>
                    </div>
                    <div class="col-6" data-oem-manual-morpho hidden>
                        <label class="form-label">Expiry date</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-expiry-date>
                    </div>
                    <div class="col-6" data-oem-manual-morpho hidden>
                        <label class="form-label">Year(s)</label>
                        <input type="text" class="form-control form-control-sm" data-oem-manual-years>
                    </div>
                </div>
                <div class="mt-2 text-end">
                    <button type="button" class="btn btn-sm btn-primary" data-oem-manual-save>Save manual results</button>
                </div>
            </div>
        </form>
    </dialog>
@endcan
