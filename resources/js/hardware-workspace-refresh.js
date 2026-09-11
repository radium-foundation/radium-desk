import { workspaceFetch, workspaceFetchHeaders } from './workspace/http';
import { initHardwareDashboardSelection } from './hardware-dashboard-selection';

const HARDWARE_QUEUE = 'hardware';

export const isHardwareWorkspaceActive = (pageRoot) => (
    pageRoot?.dataset?.liveQueue === HARDWARE_QUEUE
    || pageRoot?.dataset?.liveWorkspace === HARDWARE_QUEUE
);

const updateQueueCounts = (pageRoot, counts) => {
    Object.entries(counts ?? {}).forEach(([queue, count]) => {
        const chip = pageRoot.querySelector(`[data-hardware-queue-count="${queue}"]`);
        if (chip) {
            chip.textContent = `(${count})`;
        }
    });
};

const updateHardwareChipCount = (pageRoot, count) => {
    if (count === undefined || count === null) {
        return;
    }

    const chip = pageRoot.querySelector('[data-dashboard-case-filter-count="hardware"]');
    if (chip) {
        chip.textContent = `(${count})`;
    }
};

export const refreshHardwareWorkspace = async (pageRoot, { preserveSelection = false } = {}) => {
    if (!isHardwareWorkspaceActive(pageRoot)) {
        return false;
    }

    const url = pageRoot.dataset.hardwareWorkspaceUrl;
    if (!url) {
        return false;
    }

    const params = new URLSearchParams(window.location.search);
    const query = new URLSearchParams();
    query.set('workspace', 'hardware');
    const hwQueue = params.get('hw_queue');
    const search = params.get('q');
    if (hwQueue) {
        query.set('hw_queue', hwQueue);
    }
    if (search) {
        query.set('q', search);
    }

    const response = await workspaceFetch(`${url}?${query.toString()}`, {
        headers: workspaceFetchHeaders('application/json'),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok !== true) {
        return false;
    }

    const host = pageRoot.querySelector('[data-hardware-workspace]');
    if (host && payload.workspace_html) {
        const selected = preserveSelection
            ? new Set(Array.from(host.querySelectorAll('[data-hardware-select]:checked')).map((el) => el.value))
            : null;

        host.outerHTML = payload.workspace_html;
        const refreshedHost = pageRoot.querySelector('[data-hardware-workspace]');
        if (selected && refreshedHost) {
            refreshedHost.querySelectorAll('[data-hardware-select]').forEach((el) => {
                if (selected.has(el.value)) {
                    el.checked = true;
                }
            });
        }

        initHardwareDashboardSelection(pageRoot);
    }

    if (payload.counts) {
        updateQueueCounts(pageRoot, payload.counts);
    }

    updateHardwareChipCount(pageRoot, payload.hardware_chip_count);

    return true;
};

export const initHardwareWorkspaceRefresh = (pageRoot) => {
    if (!pageRoot) {
        return () => {};
    }

    let timer = null;
    let inFlight = false;

    const pollIntervalMs = () => {
        if (document.hidden) {
            return Number(
                pageRoot.dataset.hardwareWorkspacePollIdleMs
                || pageRoot.dataset.liveIntervalIdle
                || 60000,
            );
        }

        return Number(
            pageRoot.dataset.hardwareWorkspacePollActiveMs
            || pageRoot.dataset.liveIntervalActive
            || 20000,
        );
    };

    const schedulePoll = () => {
        window.clearTimeout(timer);
        if (!isHardwareWorkspaceActive(pageRoot)) {
            return;
        }

        timer = window.setTimeout(async () => {
            if (!inFlight && isHardwareWorkspaceActive(pageRoot)) {
                inFlight = true;
                try {
                    await refreshHardwareWorkspace(pageRoot, { preserveSelection: true });
                } finally {
                    inFlight = false;
                }
            }
            schedulePoll();
        }, pollIntervalMs());
    };

    const onRefresh = () => {
        void refreshHardwareWorkspace(pageRoot, { preserveSelection: true });
    };

    document.addEventListener('hardware-workspace:refresh', onRefresh);

    const onVisibilityChange = () => {
        if (isHardwareWorkspaceActive(pageRoot)) {
            schedulePoll();
        }
    };

    document.addEventListener('visibilitychange', onVisibilityChange);

    if (isHardwareWorkspaceActive(pageRoot)) {
        schedulePoll();
    }

    return () => {
        window.clearTimeout(timer);
        document.removeEventListener('hardware-workspace:refresh', onRefresh);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    };
};
