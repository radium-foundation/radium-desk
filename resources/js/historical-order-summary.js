import * as bootstrap from 'bootstrap';
import { formatIntakePreviewValue } from './intake-search-flow';

const PAYMENT_LABELS = {
    paid: '✓ Paid',
    unpaid: 'Unpaid',
    partial: 'Partial',
    unknown: 'Unknown',
};

const TENURE_LABELS = {
    active: 'Subscription active',
    ended: '🚫 Subscription ended',
    unknown: 'Subscription status: Unknown',
};

export const formatHistoricalDate = (value) => {
    if (!value) {
        return '—';
    }

    const parts = String(value).split('-');

    if (parts.length !== 3) {
        return formatIntakePreviewValue(value);
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
};

export const buildHistoricalOrderSummaryHtml = (summary) => {
    if (!summary) {
        return '';
    }

    const paymentClass = summary.payment_display === 'paid'
        ? 'historical-order-summary__payment--paid'
        : 'historical-order-summary__payment--neutral';

    const tenureClass = summary.tenure_display === 'ended'
        ? 'historical-order-summary__tenure--ended'
        : summary.tenure_display === 'active'
            ? 'historical-order-summary__tenure--active'
            : 'historical-order-summary__tenure--unknown';

    const provenanceHtml = Array.isArray(summary.provenance) && summary.provenance.length > 0
        ? `
            <div class="historical-order-summary__provenance mt-2">
                <div class="small fw-semibold mb-1">Historical source</div>
                <ul class="list-unstyled small mb-0">
                    ${summary.provenance.map((row) => `
                        <li>
                            <span class="text-muted">${row.label}</span>
                            ${row.value ? ` · ${formatIntakePreviewValue(row.value)}` : ''}
                        </li>
                    `).join('')}
                </ul>
            </div>
        `
        : '';

    const tenureEndHtml = summary.tenure_display === 'ended' && summary.tenure_end_date
        ? `<div class="small">End date: ${formatHistoricalDate(summary.tenure_end_date)}</div>`
        : '';

    return `
        <div class="historical-order-summary border rounded p-3 bg-light-subtle">
            <div class="small fw-semibold mb-2">Historical Order Summary</div>
            <dl class="dashboard-legacy-preview-card__fields mb-0">
                <dt>Order ID</dt>
                <dd>${formatIntakePreviewValue(summary.order_id)}</dd>
                <dt>Order date</dt>
                <dd>${formatHistoricalDate(summary.order_date)}</dd>
                <dt>Order year</dt>
                <dd>${formatIntakePreviewValue(summary.order_year)}</dd>
                <dt>Payment</dt>
                <dd class="${paymentClass}">${PAYMENT_LABELS[summary.payment_display] ?? PAYMENT_LABELS.unknown}</dd>
                <dt>Payment date</dt>
                <dd>${formatHistoricalDate(summary.payment_date)}</dd>
                <dt>Amount</dt>
                <dd>${formatIntakePreviewValue(summary.order_amount)}</dd>
                <dt>Invoice</dt>
                <dd>${formatIntakePreviewValue(summary.invoice_number)}</dd>
                <dt>AWB</dt>
                <dd>${formatIntakePreviewValue(summary.awb)}</dd>
                <dt>Product / model</dt>
                <dd>${formatIntakePreviewValue(summary.product_model)}</dd>
                <dt>Serial number</dt>
                <dd>${formatIntakePreviewValue(summary.serial_number)}</dd>
                <dt>Tenure</dt>
                <dd class="${tenureClass}">
                    <div>${TENURE_LABELS[summary.tenure_display] ?? TENURE_LABELS.unknown}</div>
                    ${tenureEndHtml}
                </dd>
            </dl>
            ${provenanceHtml}
            <div class="small text-muted mt-2 mb-0">Historical · read-only · not authoritative</div>
        </div>
    `;
};

const getModal = () => document.getElementById('historicalOrderSummaryModal');

export const openHistoricalOrderSummaryModal = async (summaryUrl) => {
    const modalElement = getModal();

    if (!modalElement || !summaryUrl) {
        return false;
    }

    const body = modalElement.querySelector('[data-historical-order-summary-body]');
    const errorElement = modalElement.querySelector('[data-historical-order-summary-error]');

    if (body) {
        body.innerHTML = '<div class="text-muted small">Loading historical order summary…</div>';
    }

    if (errorElement) {
        errorElement.textContent = '';
        errorElement.classList.add('d-none');
    }

    bootstrap.Modal.getOrCreateInstance(modalElement).show();

    try {
        const response = await fetch(summaryUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const data = await response.json();

        if (!response.ok) {
            if (errorElement) {
                errorElement.textContent = data.message ?? 'Unable to load historical order summary.';
                errorElement.classList.remove('d-none');
            }

            if (body) {
                body.innerHTML = '';
            }

            return false;
        }

        if (body) {
            body.innerHTML = buildHistoricalOrderSummaryHtml(data.summary);
        }

        return true;
    } catch {
        if (errorElement) {
            errorElement.textContent = 'Unable to load historical order summary.';
            errorElement.classList.remove('d-none');
        }

        if (body) {
            body.innerHTML = '';
        }

        return false;
    }
};

export const initHistoricalOrderSummary = () => {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-historical-order-summary-open]');

        if (!trigger) {
            return;
        }

        event.preventDefault();

        const summaryUrl = trigger.dataset.summaryUrl ?? '';

        if (summaryUrl !== '') {
            openHistoricalOrderSummaryModal(summaryUrl);
        }
    });
};
