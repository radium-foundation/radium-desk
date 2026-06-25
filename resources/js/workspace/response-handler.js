const replaceInnerHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined || html === null) {
        return;
    }

    element.innerHTML = html;
};

const applyDomPatch = (selector, html, strategy = 'innerHTML') => {
    const element = document.querySelector(selector);

    if (!element || !html) {
        return;
    }

    if (strategy === 'outerHTML') {
        element.outerHTML = html;
        return;
    }

    if (strategy === 'replace') {
        element.outerHTML = html;
        return;
    }

    element.innerHTML = html;
};

const applyFragments = (fragments, host) => {
    (fragments ?? []).forEach((fragment) => {
        const target = fragment.target
            ? document.querySelector(fragment.target)
            : host?.querySelector('[data-workspace-modal-content]');

        if (!target) {
            return;
        }

        if (fragment.strategy === 'outerHTML') {
            target.outerHTML = fragment.html;
            return;
        }

        target.innerHTML = fragment.html;
    });
};

const applyTargets = (targets, hooks) => {
    (targets ?? []).forEach((target) => {
        applyDomPatch(target.selector, target.html, target.strategy ?? 'innerHTML');

        if (hooks.initTooltips) {
            hooks.initTooltips(document.querySelector(target.selector)?.parentElement ?? document);
        }
    });
};

const applyKpis = (refresh, hooks) => {
    if (refresh?.kpis_html) {
        replaceInnerHtml('dashboard-action-stats', refresh.kpis_html.action_stats_html);
        replaceInnerHtml('dashboard-sla-cards', refresh.kpis_html.sla_cards_html);
        return;
    }

    if (refresh?.kpis && hooks.refreshDashboardKpis) {
        hooks.refreshDashboardKpis();
    }
};

export const createResponseHandler = (hooks = {}) => {
    const showToast = (toast, fallbackMessage) => {
        if (toast?.show === false) {
            return;
        }

        const message = toast?.message ?? fallbackMessage;

        if (!message || !hooks.showToast) {
            return;
        }

        hooks.showToast(message, toast?.variant ?? 'success');
    };

    const closeWorkspaceHost = (host) => {
        if (!host || !window.bootstrap?.Modal) {
            return;
        }

        const modal = window.bootstrap.Modal.getInstance(host) ?? window.bootstrap.Modal.getOrCreateInstance(host);
        modal.hide();
    };

    const applyWorkspaceResponse = async (data, host) => {
        if (!data || typeof data !== 'object') {
            return;
        }

        if (!data.success) {
            applyFragments(data.refresh?.fragments, host);
            applyTargets(data.refresh?.targets, hooks);
            showToast(data.toast, data.message);
            return;
        }

        applyFragments(data.refresh?.fragments, host);
        applyTargets(data.refresh?.targets, hooks);

        if (data.refresh?.replace_row && hooks.replaceServiceCaseRow) {
            hooks.replaceServiceCaseRow(
                data.refresh.replace_row.incident_id,
                data.refresh.replace_row.html,
            );
        }

        applyKpis(data.refresh, hooks);

        if (data.ui?.close_workspace_host) {
            closeWorkspaceHost(host);
        }

        showToast(data.toast, data.message);

        if (data.ui?.redirect?.url) {
            const navigate = data.ui.redirect.replace ? location.replace : location.assign;
            navigate(data.ui.redirect.url);
        }
    };

    return {
        applyWorkspaceResponse,
    };
};
