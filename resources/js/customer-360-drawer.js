import { getWorkspaceSession } from './workspace/session';

const SESSION_REASON = 'customer-360-drawer';

const copyTextToClipboard = async (text) => {
    await navigator.clipboard.writeText(text);
};

const isInteractiveTarget = (target) => {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(
        target.closest('button, input, textarea, select, label, [data-workspace-trigger], [data-inline-transaction], [data-inline-serial], .dashboard-row-actions, .dashboard-select-cell')
    );
};

export const initCustomer360Drawer = ({ pageRoot, showToast, initTooltips } = {}) => {
    const root = pageRoot ?? document.getElementById('dashboard-page');

    if (!root) {
        return null;
    }

    const drawer = document.querySelector('[data-customer-360-drawer]');

    if (!drawer) {
        return null;
    }

    const backdrop = drawer.querySelector('[data-customer-360-backdrop]');
    const panel = drawer.querySelector('[data-customer-360-panel]');
    const closeButton = drawer.querySelector('[data-customer-360-close]');
    const contentHost = drawer.querySelector('[data-customer-360-content-host]');
    const loadingState = drawer.querySelector('[data-customer-360-loading]');
    const errorState = drawer.querySelector('[data-customer-360-error]');
    const subtitle = drawer.querySelector('[data-customer-360-subtitle]');
    const baseUrl = root.getAttribute('data-customer-360-url');

    if (!baseUrl || !contentHost) {
        return null;
    }

    let activeIncidentId = null;
    let fetchController = null;
    let previouslyFocusedElement = null;

    const setLoading = (isLoading) => {
        loadingState.hidden = !isLoading;
    };

    const setError = (message = '') => {
        if (!errorState) {
            return;
        }

        if (message === '') {
            errorState.classList.add('d-none');
            errorState.textContent = '';

            return;
        }

        errorState.classList.remove('d-none');
        errorState.textContent = message;
    };

    const clearContent = () => {
        contentHost.innerHTML = '';
    };

    const bindCopyActions = () => {
        contentHost.querySelectorAll('[data-customer-360-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.dataset.copyValue?.trim() ?? '';

                if (value === '') {
                    return;
                }

                await copyTextToClipboard(value);

                const label = button.dataset.customer360Copy === 'mobile' ? 'Mobile number' : 'Serial number';
                showToast?.(`${label} copied`);
            });
        });
    };

    const loadContent = async (incidentId) => {
        fetchController?.abort();
        fetchController = new AbortController();

        setError('');
        clearContent();
        setLoading(true);

        try {
            const response = await fetch(`${baseUrl}/${incidentId}/customer-360`, {
                method: 'GET',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: fetchController.signal,
            });

            if (!response.ok) {
                throw new Error('Unable to load customer details.');
            }

            const html = await response.text();
            contentHost.innerHTML = html;
            bindCopyActions();
            initTooltips?.(contentHost);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            setError('Unable to load customer details. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const close = () => {
        if (!drawer.classList.contains('is-open')) {
            return;
        }

        fetchController?.abort();
        fetchController = null;
        activeIncidentId = null;

        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('customer-360-drawer-open');

        getWorkspaceSession().release(SESSION_REASON);

        clearContent();
        setError('');
        setLoading(false);

        if (subtitle) {
            subtitle.textContent = '';
        }

        if (previouslyFocusedElement instanceof HTMLElement) {
            previouslyFocusedElement.focus();
        }

        previouslyFocusedElement = null;
    };

    const open = async (incidentId, referenceLabel = '') => {
        if (activeIncidentId === incidentId && drawer.classList.contains('is-open')) {
            return;
        }

        previouslyFocusedElement = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

        activeIncidentId = incidentId;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('customer-360-drawer-open');

        if (subtitle) {
            subtitle.textContent = referenceLabel;
        }

        getWorkspaceSession().acquire(SESSION_REASON, {
            incidentId: Number(incidentId),
        });

        closeButton?.focus();
        await loadContent(incidentId);
    };

    const handleRowClick = (event) => {
        const row = event.target.closest('tr[data-incident-id]');

        if (!row || !root.contains(row)) {
            return;
        }

        if (isInteractiveTarget(event.target)) {
            return;
        }

        const incidentId = row.dataset.incidentId;
        const referenceLink = row.querySelector('.case-reference-link');
        const referenceLabel = referenceLink?.textContent?.trim() ?? '';

        if (!incidentId) {
            return;
        }

        event.preventDefault();
        open(incidentId, referenceLabel);
    };

    root.addEventListener('click', handleRowClick);

    closeButton?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !drawer.classList.contains('is-open')) {
            return;
        }

        event.preventDefault();
        close();
    });

    panel?.addEventListener('click', (event) => {
        event.stopPropagation();
    });

    return {
        open,
        close,
        isOpen: () => drawer.classList.contains('is-open'),
    };
};
