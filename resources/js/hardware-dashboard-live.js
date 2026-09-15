const hardwareWorkspace = () => document.getElementById('dashboard-hardware-workspace');

const currentHardwareQueue = () => hardwareWorkspace()?.dataset.hardwareQueue ?? '';

const formatCount = (value) => `(${Number(value) || 0})`;

export const applyHardwareFilterCounts = (counts = {}, hardwareCount = null) => {
    Object.entries(counts).forEach(([queue, count]) => {
        const chip = document.querySelector(
            `.dashboard-operation-queues [href*="hw_queue=${queue}"] .dashboard-case-filter-chip__count`,
        );
        if (chip) {
            chip.textContent = formatCount(count);
        }
    });

    if (hardwareCount !== null) {
        const hardwareChip = document.querySelector(
            '[data-dashboard-case-filter-count="hardware"]',
        );
        if (hardwareChip) {
            hardwareChip.textContent = hardwareChip.textContent.includes('(')
                ? formatCount(hardwareCount)
                : String(hardwareCount);
        }
    }
};

const rowByFulfilmentId = (fulfilmentId) => document.querySelector(
    `#dashboard-hardware-body tr[data-hardware-fulfilment-id="${fulfilmentId}"]`,
);

export const applyHardwareLivePayload = (payload) => {
    if (!payload) {
        return;
    }

    const body = document.getElementById('dashboard-hardware-body');
    applyHardwareFilterCounts(payload.counts ?? {}, payload.hardware_count ?? null);

    (payload.remove_fulfilment_ids ?? []).forEach((id) => {
        rowByFulfilmentId(id)?.remove();
    });

    if (!body) {
        return;
    }

    const empty = body.querySelector('.dashboard-cases-empty');
    if (empty && (payload.rows ?? []).length > 0) {
        empty.closest('tr')?.remove();
    }

    (payload.rows ?? []).forEach((row) => {
        if (!row?.html || !row.fulfilment_id) {
            return;
        }

        const existing = rowByFulfilmentId(row.fulfilment_id);
        const template = document.createElement('tbody');
        template.innerHTML = row.html.trim();
        const next = template.querySelector('tr');
        if (!next) {
            return;
        }

        if (existing) {
            existing.replaceWith(next);
        } else if ((currentHardwareQueue() === '' || row.queue === currentHardwareQueue())) {
            body.prepend(next);
        }
    });
};

export const fetchHardwareLiveRows = async (pageRoot, fulfilmentIds) => {
    const url = pageRoot?.dataset.liveHardwareUrl;
    if (!url || !fulfilmentIds?.length) {
        return null;
    }

    const params = new URLSearchParams();
    fulfilmentIds.forEach((id) => params.append('ids[]', String(id)));
    const hwQueue = currentHardwareQueue();
    if (hwQueue) {
        params.set('hw_queue', hwQueue);
    }

    const response = await fetch(`${url}?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        return null;
    }

    return response.json();
};

export const handleHardwareFulfilmentsUpdated = async (pageRoot, payload) => {
    const ids = (payload?.fulfilment_ids ?? [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0);

    if (ids.length === 0) {
        return;
    }

    const live = await fetchHardwareLiveRows(pageRoot, ids);
    if (live) {
        applyHardwareLivePayload(live);
    }
};
