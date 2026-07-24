const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const openTelFallback = (telUrl) => {
    if (!telUrl) {
        return;
    }

    window.location.href = telUrl;
};

const setButtonLoading = (button, isLoading) => {
    button.disabled = isLoading;
    button.classList.toggle('is-loading', isLoading);
    button.setAttribute('aria-busy', isLoading ? 'true' : 'false');
};

const buildRequestBody = (button) => {
    const orderId = button.dataset.bonvoiceOrderId;
    const incidentId = button.dataset.bonvoiceIncidentId;

    if (orderId) {
        return { order_id: Number(orderId) };
    }

    if (incidentId) {
        return { incident_id: Number(incidentId) };
    }

    return null;
};

const showFailureToast = (button, { showToast, fallbackTel, retriable = false, message = null }) => {
    const actions = [];

    if (retriable) {
        actions.push({
            label: 'Retry',
            onClick: () => {
                initiateBonvoiceClickToCall(button, { showToast });
            },
        });
    }

    if (fallbackTel) {
        actions.push({
            label: 'Call Manually',
            onClick: () => openTelFallback(fallbackTel),
        });
    }

    if (typeof showToast === 'function') {
        showToast({
            message: message || 'Automatic calling failed.',
            variant: 'danger',
            actions,
        });
    }
};

export const initiateBonvoiceClickToCall = async (button, { showToast } = {}) => {
    const url = button.dataset.bonvoiceClickToCallUrl;
    const requestBody = buildRequestBody(button);
    const fallbackTel = button.dataset.telFallback ?? null;

    if (!url || !requestBody) {
        showFailureToast(button, { showToast, fallbackTel, retriable: false });

        return { success: false, usedFallback: false };
    }

    setButtonLoading(button, true);

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(requestBody),
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({}));
        const message = typeof payload.message === 'string' ? payload.message : null;
        const fallbackUrl = typeof payload.fallback_tel === 'string' ? payload.fallback_tel : fallbackTel;
        const retriable = payload.retriable === true;

        if (response.ok && payload.success === true) {
            showToast?.(message ?? 'Calling your registered mobile...', 'success');

            return { success: true, usedFallback: false, payload };
        }

        showFailureToast(button, {
            showToast,
            fallbackTel: fallbackUrl,
            retriable,
            message,
        });

        return { success: false, usedFallback: false, payload };
    } catch (error) {
        showFailureToast(button, {
            showToast,
            fallbackTel,
            retriable: true,
            message: 'Automatic calling failed.',
        });

        return { success: false, usedFallback: false, error };
    } finally {
        setButtonLoading(button, false);
    }
};

export const bindBonvoiceClickToCall = (root = document, { showToast } = {}) => {
    root.querySelectorAll('[data-bonvoice-click-to-call]').forEach((element) => {
        if (!(element instanceof HTMLButtonElement) || element.dataset.bonvoiceClickToCallBound === 'true') {
            return;
        }

        element.dataset.bonvoiceClickToCallBound = 'true';

        element.addEventListener('click', async (event) => {
            event.preventDefault();
            await initiateBonvoiceClickToCall(element, { showToast });
        });
    });
};

export const runBonvoiceShortcutCall = async (contentHost, { showToast } = {}) => {
    const target = contentHost?.querySelector('[data-c360-shortcut-action="call"]');

    if (!(target instanceof HTMLElement)) {
        showToast?.('Call is not available for this case.', 'danger');

        return false;
    }

    if (target instanceof HTMLButtonElement && target.dataset.bonvoiceClickToCall === '') {
        await initiateBonvoiceClickToCall(target, { showToast });

        return true;
    }

    if (target instanceof HTMLAnchorElement && target.href) {
        target.click();

        return true;
    }

    if (!target.disabled) {
        target.click();

        return true;
    }

    showToast?.(target.title?.replace(/^🔒\s*/, '') || 'Call is not available for this case.', 'danger');

    return false;
};
