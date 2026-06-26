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

const applyFragments = (fragments, host, hooks = {}) => {
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

        if (hooks.initMentionTextareas) {
            hooks.initMentionTextareas(target);
        }
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

const applyKpis = (refresh) => {
    if (!refresh?.kpis_html) {
        return;
    }

    if (refresh.kpis_html.kpi_strip_html !== undefined) {
        replaceInnerHtml('dashboard-kpi-strip', refresh.kpis_html.kpi_strip_html);

        return;
    }

    replaceInnerHtml('dashboard-action-stats', refresh.kpis_html.action_stats_html);
    replaceInnerHtml('dashboard-sla-cards', refresh.kpis_html.sla_cards_html);
};

export const createResponseHandler = (hooks = {}, lifecycle = null) => {
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
            applyFragments(data.refresh?.fragments, host, hooks);
            applyTargets(data.refresh?.targets, hooks);
            showToast(data.toast, data.message);
            return;
        }

        applyFragments(data.refresh?.fragments, host, hooks);
        applyTargets(data.refresh?.targets, hooks);

        if (data.refresh?.replace_row && hooks.replaceServiceCaseRow) {
            hooks.replaceServiceCaseRow(
                data.refresh.replace_row.incident_id,
                data.refresh.replace_row.html,
            );
        }

        if (data.refresh?.replace_rows && hooks.replaceServiceCaseRow) {
            data.refresh.replace_rows.forEach((row) => {
                hooks.replaceServiceCaseRow(row.incident_id, row.html);
            });
        }

        applyKpis(data.refresh);

        if (data.ui?.close_workspace_host) {
            closeWorkspaceHost(host);
        }

        showToast(data.toast, data.message);

        if (lifecycle) {
            await lifecycle.run('afterSuccess', data, host);
        }

        if (data.ui?.redirect?.url) {
            const navigate = data.ui.redirect.replace ? location.replace : location.assign;
            navigate(data.ui.redirect.url);
        }
    };

    return {
        applyWorkspaceResponse,
    };
};
