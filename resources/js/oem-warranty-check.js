const EXTENSION_MESSAGE = 'RDESK_OEM_WARRANTY';

const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const postJson = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = payload?.message ?? payload?.errors ? JSON.stringify(payload.errors) : 'Request failed';
        throw new Error(message);
    }

    return payload;
};

const getJson = async (url) => {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(payload?.message ?? 'Request failed');
    }

    return payload;
};

export function initOemWarrantyCheck({ showToast } = {}) {
    const root = document.querySelector('[data-oem-warranty-check]');

    if (!root) {
        return;
    }

    const dialog = document.querySelector('[data-oem-warranty-dialog]');
    const statusLabel = root.querySelector('[data-oem-warranty-status-label]');
    const summary = root.querySelector('[data-oem-warranty-summary]');
    const refreshBtn = root.querySelector('[data-oem-warranty-refresh]');
    const providerSelect = dialog?.querySelector('[data-oem-warranty-provider-select]');
    const providerRow = dialog?.querySelector('[data-oem-warranty-provider-row]');
    const providerLabel = dialog?.querySelector('[data-oem-warranty-provider-label]');
    const draftPreview = dialog?.querySelector('[data-oem-warranty-draft-preview]');
    const confirmBtn = dialog?.querySelector('[data-oem-warranty-confirm]');
    const cancelBtn = dialog?.querySelector('[data-oem-warranty-cancel]');
    const manualToggle = dialog?.querySelector('[data-oem-warranty-manual-toggle]');
    const manualForm = dialog?.querySelector('[data-oem-warranty-manual-form]');
    const manualSave = dialog?.querySelector('[data-oem-manual-save]');

    const contextUrl = root.dataset.contextUrl;
    const startUrl = root.dataset.startUrl;
    const manualUrl = root.dataset.manualUrl;
    const draftApiUrl = root.dataset.draftApiUrl;
    const orderId = root.dataset.orderId;
    const orderSerial = root.dataset.serialNumber;

    let context = null;
    let activeSession = null;
    let handoffToken = null;
    let pollTimer = null;

    const toast = (message, type = 'info') => {
        if (typeof showToast === 'function') {
            showToast(message, type);
            return;
        }

        // eslint-disable-next-line no-console
        console.log(`[oem-warranty] ${message}`);
    };

    const setStatus = (label) => {
        if (statusLabel) {
            statusLabel.textContent = label;
        }
    };

    const renderSummary = () => {
        if (!summary || !context?.current_checks?.length) {
            if (summary) {
                summary.textContent = '';
            }

            return;
        }

        const lines = context.current_checks.map((check) => {
            const when = check.checked_at ? new Date(check.checked_at).toLocaleString() : '—';
            return `${check.provider_label}: ${check.outcome} (${when})`;
        });

        summary.textContent = lines.join(' · ');
        setStatus('Saved');
    };

    const loadContext = async () => {
        context = await getJson(contextUrl);
        renderSummary();

        if (context.suggested_provider && providerSelect) {
            providerSelect.value = context.suggested_provider;
        }

        if (providerRow) {
            providerRow.hidden = !context.requires_provider_selection;
        }
    };

    const postDraftFromExtension = async (draft) => {
        if (context?.extension_dom_enabled !== true) {
            toast('Automated capture is disabled. Enter results manually after checking the portal.', 'warning');
            return;
        }

        await fetch(draftApiUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                handoff_token: handoffToken,
                capture_method: 'extension_dom',
                outcome: draft.outcome,
                rows: draft.rows ?? [],
                provider_raw: draft.provider_raw ?? null,
            }),
        }).then(async (response) => {
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload?.message ?? 'Draft submission failed');
            }
        });
    };

    const pollSession = async () => {
        if (!activeSession?.public_id) {
            return;
        }

        const url = `${startUrl.replace(/\/sessions$/, `/sessions/${activeSession.public_id}`)}`;

        try {
            const payload = await getJson(url);
            if (payload.session?.draft) {
                if (draftPreview) {
                    draftPreview.textContent = JSON.stringify(payload.session.draft, null, 2);
                }
                setStatus('Results ready for review');
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
                dialog?.showModal();
            }
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn(error);
        }
    };

    const beginLookup = async () => {
        await loadContext();

        let provider = context?.suggested_provider;
        if (context?.requires_provider_selection) {
            provider = providerSelect?.value;
        }

        if (!provider) {
            dialog?.showModal();
            toast('Select MantraTec or Morpho before starting a check.', 'warning');
            return;
        }

        setStatus('Opening portal');
        const started = await postJson(startUrl, { provider });
        activeSession = started.session;
        handoffToken = started.handoff_token;

        const message = {
            type: `${EXTENSION_MESSAGE}_START`,
            version: 1,
            order_id: Number(orderId),
            provider,
            serial_number: started.session.serial_number,
            portal_url: started.session.portal_url,
            handoff_token: handoffToken,
            draft_api_url: draftApiUrl,
            session_public_id: started.session.public_id,
            extension_dom_enabled: context?.extension_dom_enabled === true,
        };

        window.postMessage(message, window.location.origin);

        if (typeof chrome !== 'undefined' && chrome.runtime?.sendMessage) {
            try {
                chrome.runtime.sendMessage(context.extension_id, message);
            } catch (error) {
                toast('Install or enable the Desk OEM Warranty Helper extension to automate prefill.', 'warning');
            }
        } else {
            window.open(started.session.portal_url, '_blank', 'noopener,noreferrer');
            toast('Extension unavailable — portal opened. Use manual entry if needed.', 'warning');
        }

        setStatus('Awaiting CAPTCHA/verification');
        providerLabel.textContent = `${started.session.provider_label} · Serial ${started.session.serial_number}`;

        pollTimer = window.setInterval(pollSession, 2500);
    };

    window.addEventListener('message', async (event) => {
        if (event.source !== window || event.origin !== window.location.origin) {
            return;
        }

        const data = event.data;
        if (!data || typeof data !== 'object' || !String(data.type || '').startsWith(`${EXTENSION_MESSAGE}_`)) {
            return;
        }

        if (data.type === `${EXTENSION_MESSAGE}_DRAFT` && handoffToken) {
            try {
                await postDraftFromExtension(data);
                setStatus('Results ready for review');
                await pollSession();
            } catch (error) {
                toast(error.message, 'error');
            }
        }
    });

    refreshBtn?.addEventListener('click', async () => {
        try {
            await beginLookup();
        } catch (error) {
            toast(error.message, 'error');
            setStatus('Failed');
        }
    });

    cancelBtn?.addEventListener('click', async () => {
        if (!activeSession?.public_id) {
            dialog?.close();
            return;
        }

        const cancelUrl = `${startUrl.replace(/\/sessions$/, `/sessions/${activeSession.public_id}/cancel`)}`;
        await postJson(cancelUrl, {});
        activeSession = null;
        handoffToken = null;
        setStatus('Not checked');
        dialog?.close();
    });

    confirmBtn?.addEventListener('click', async (event) => {
        event.preventDefault();
        if (!activeSession?.public_id) {
            return;
        }

        const confirmUrl = `${startUrl.replace(/\/sessions$/, `/sessions/${activeSession.public_id}/confirm`)}`;
        await postJson(confirmUrl, {});
        toast('OEM check saved.', 'success');
        activeSession = null;
        handoffToken = null;
        dialog?.close();
        await loadContext();
    });

    manualToggle?.addEventListener('click', () => {
        if (manualForm) {
            manualForm.hidden = !manualForm.hidden;
        }
    });

    manualSave?.addEventListener('click', async () => {
        const provider = (context?.requires_provider_selection ? providerSelect?.value : context?.suggested_provider) ?? providerSelect?.value;
        const outcome = dialog.querySelector('[data-oem-manual-outcome]')?.value ?? 'inconclusive';
        const rows = [];

        const serialFromSession = activeSession?.serial_number ?? orderSerial;

        if (provider === 'mantratec') {
            rows.push({
                serial_number: serialFromSession,
                warranty_expiry_date: dialog.querySelector('[data-oem-manual-warranty-expiry]')?.value,
                rd_expiry_date: dialog.querySelector('[data-oem-manual-rd-expiry]')?.value,
                model: dialog.querySelector('[data-oem-manual-model]')?.value,
            });
        } else {
            rows.push({
                serial_number: serialFromSession,
                order_number: dialog.querySelector('[data-oem-manual-order-number]')?.value,
                whitelisting_date: dialog.querySelector('[data-oem-manual-whitelisting-date]')?.value,
                expiry_date: dialog.querySelector('[data-oem-manual-expiry-date]')?.value,
                validity_years: dialog.querySelector('[data-oem-manual-years]')?.value,
            });
        }

        await postJson(manualUrl, { provider, outcome, rows });
        toast('Manual OEM check saved.', 'success');
        dialog?.close();
        await loadContext();
    });

    loadContext().catch(() => {
        setStatus('Unavailable');
    });
}
