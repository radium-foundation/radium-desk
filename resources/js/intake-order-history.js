const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

const warningBadgeClass = (code) => {
    if (code === 'serial_missing' || code === 'enrichment_serial_skipped') {
        return 'text-bg-warning';
    }

    if (code === 'serial_conflict') {
        return 'text-bg-danger';
    }

    return 'text-bg-secondary';
};

const renderPriorReferenceBanner = (reference) => {
    if (!reference?.source_order_id) {
        return '';
    }

    const serial = reference.serial_number
        ? escapeHtml(reference.serial_number)
        : '<span class="text-muted">—</span>';
    const serviceHistory = Array.isArray(reference.service_history)
        ? escapeHtml(reference.service_history.join(', '))
        : escapeHtml(reference.product_service ?? '—');

    return `
        <div class="alert alert-info py-2 small mb-3">
            <div class="fw-semibold mb-1">Valid prior RD completion (reference only)</div>
            <div>Prior order: <strong>${escapeHtml(reference.source_order_id)}</strong></div>
            <div>Reference serial: <strong>${serial}</strong> (not assigned to this order)</div>
            <div>Service / product: ${serviceHistory}</div>
        </div>
    `;
};

export const renderIntakeOrderHistory = (container, orderHistory) => {
    if (!container) {
        return;
    }

    if (!orderHistory?.resolved || !Array.isArray(orderHistory.orders) || orderHistory.orders.length === 0) {
        if (orderHistory?.ambiguous && orderHistory.ambiguity_reason) {
            container.classList.remove('d-none');
            container.innerHTML = `
                <div class="alert alert-warning py-2 small mb-0">
                    <strong>Customer order history unavailable:</strong>
                    ${escapeHtml(orderHistory.ambiguity_reason)}
                </div>
            `;

            return;
        }

        if (orderHistory?.valid_prior_rd_reference) {
            container.classList.remove('d-none');
            container.innerHTML = renderPriorReferenceBanner(orderHistory.valid_prior_rd_reference);

            return;
        }

        container.classList.add('d-none');
        container.innerHTML = '';

        return;
    }

    const priorReferenceHtml = orderHistory.valid_prior_rd_reference
        ? renderPriorReferenceBanner(orderHistory.valid_prior_rd_reference)
        : '';

    const rows = orderHistory.orders.map((row) => {
        const warnings = Array.isArray(row.warnings) ? row.warnings : [];
        const warningHtml = warnings.map((warning) => `
            <span class="badge ${warningBadgeClass(warning.code)} me-1 mb-1">${escapeHtml(warning.message)}</span>
        `).join('');

        const serialLabel = row.serial_missing
            ? '<span class="text-danger fw-semibold">Missing</span>'
            : escapeHtml(row.serial_number ?? '—');

        const applySerialButton = row.serial_number && !row.serial_missing
            ? `<button type="button" class="btn btn-outline-secondary btn-sm" data-intake-apply-serial="${escapeHtml(row.serial_number)}">Use serial on form</button>`
            : '';

        return `
            <tr class="${row.is_highlighted ? 'table-primary' : ''}">
                <td class="fw-semibold">${escapeHtml(row.order_id)}</td>
                <td>${escapeHtml(row.order_date ? new Date(row.order_date).toLocaleDateString() : '—')}</td>
                <td>${escapeHtml(row.spoke_label ?? row.spoke)}</td>
                <td>${escapeHtml(row.product_service ?? '—')}</td>
                <td>${serialLabel}</td>
                <td>${escapeHtml(row.validity_label ?? 'Unknown')}</td>
                <td class="small">${warningHtml}${applySerialButton}</td>
            </tr>
        `;
    }).join('');

    container.classList.remove('d-none');
    container.innerHTML = `
        ${priorReferenceHtml}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2">
                <h3 class="h6 mb-0">Customer service order history</h3>
                <p class="small text-muted mb-0">Newest first. Serials are not applied automatically.</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Product / service</th>
                                <th>Serial</th>
                                <th>Validity</th>
                                <th>Alerts</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    container.querySelectorAll('[data-intake-apply-serial]').forEach((button) => {
        button.addEventListener('click', () => {
            const serial = button.getAttribute('data-intake-apply-serial');
            const serialField = document.querySelector('#intake_serial_number')
                ?? document.querySelector('#intake_new_contact_serial_number');

            if (serialField && serial) {
                serialField.value = serial;
                serialField.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });
};
