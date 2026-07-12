const setField = (root, field, value) => {
    const element = root.querySelector(`[data-field="${field}"]`);

    if (! element) {
        return;
    }

    element.textContent = value ?? '—';
};

const metadataSummary = (metadata) => {
    if (! metadata || typeof metadata !== 'object' || Object.keys(metadata).length === 0) {
        return '—';
    }

    const keys = Object.keys(metadata).slice(0, 4);

    return keys.map((key) => `${key}: ${JSON.stringify(metadata[key])}`).join(' · ');
};

const openDrawer = async (drawer, url) => {
    const loading = drawer.querySelector('[data-automation-health-drawer-loading]');
    const error = drawer.querySelector('[data-automation-health-drawer-error]');
    const content = drawer.querySelector('[data-automation-health-drawer-content]');
    const offcanvas = window.bootstrap?.Offcanvas?.getOrCreateInstance(drawer);

    loading?.classList.remove('d-none');
    error?.classList.add('d-none');
    content?.classList.add('d-none');
    offcanvas?.show();

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            throw new Error('Unable to load execution detail.');
        }

        const payload = await response.json();
        const execution = payload.execution ?? {};

        setField(drawer, 'policy_label', execution.policy_label);
        setField(drawer, 'action_label', execution.action_label);
        setField(drawer, 'subject', execution.subject);
        setField(drawer, 'metadata_summary', metadataSummary(execution.metadata));
        setField(drawer, 'started_at_display', execution.started_at_display);
        setField(drawer, 'completed_at_display', execution.completed_at_display);
        setField(drawer, 'duration_display', execution.duration_display);
        setField(drawer, 'status_label', execution.status_label);
        setField(drawer, 'triggered_by', execution.triggered_by);
        setField(drawer, 'retry_status', execution.retry_status);
        setField(drawer, 'error_message', execution.error_message);
        setField(drawer, 'metadata_raw', JSON.stringify(execution.metadata ?? {}, null, 2));

        loading?.classList.add('d-none');
        content?.classList.remove('d-none');
    } catch (fetchError) {
        loading?.classList.add('d-none');

        if (error) {
            error.textContent = fetchError instanceof Error ? fetchError.message : 'Unable to load execution detail.';
            error.classList.remove('d-none');
        }
    }
};

export const initAutomationHealth = () => {
    const drawer = document.querySelector('[data-automation-health-drawer]');

    if (! drawer) {
        return;
    }

    const handleRowActivate = (row) => {
        const url = row.dataset.automationHealthDetailUrl;

        if (! url) {
            return;
        }

        openDrawer(drawer, url);
    };

    document.querySelectorAll('.automation-health-row').forEach((row) => {
        row.addEventListener('click', () => handleRowActivate(row));
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                handleRowActivate(row);
            }
        });
    });
};
