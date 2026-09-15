const hardwareWorkspace = () => document.getElementById('dashboard-hardware-workspace');

const currentHardwareScope = () => hardwareWorkspace()?.dataset.hardwareScope ?? 'active';

const currentHardwareFilter = () => hardwareWorkspace()?.dataset.hardwareFilter ?? 'all';

const formatCount = (value) => `(${Number(value) || 0})`;

export const applyHardwareFilterCounts = (scopeCounts = {}, filterCounts = {}, hardwareCount = null) => {
    Object.entries(scopeCounts).forEach(([scope, count]) => {
        const chip = document.querySelector(`[data-hardware-scope-count="${scope}"]`);
        if (chip) {
            chip.textContent = formatCount(count);
        }
    });

    Object.entries(filterCounts).forEach(([filter, count]) => {
        const chip = document.querySelector(`[data-hardware-filter-count="${filter}"]`);
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

const rowMatchesActiveView = (row) => {
    const scope = currentHardwareScope();
    const filter = currentHardwareFilter();
    const rowScope = row?.scope ?? (row?.queue === 'completed' ? 'shipped' : 'active');
    const rowFilter = row?.filter ?? row?.queue ?? 'all';

    if (scope === 'shipped') {
        return rowScope === 'shipped' || row?.queue === 'completed';
    }

    if (rowScope === 'shipped' || row?.queue === 'completed') {
        return false;
    }

    if (filter === 'all') {
        return true;
    }

    return rowFilter === filter;
};

export const applyHardwareLivePayload = (payload) => {
    if (!payload) {
        return;
    }

    const body = document.getElementById('dashboard-hardware-body');
    applyHardwareFilterCounts(
        payload.scope_counts ?? {},
        payload.filter_counts ?? {},
        payload.hardware_count ?? null,
    );

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
            if (rowMatchesActiveView(row)) {
                existing.replaceWith(next);
            } else {
                existing.remove();
            }
        } else if (rowMatchesActiveView(row)) {
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
    params.set('hw_scope', currentHardwareScope());
    const filter = currentHardwareFilter();
    if (filter && filter !== 'all') {
        params.set('hw_filter', filter);
    }

    const searchInput = document.getElementById('hardware-quick-filter-input');
    const searchValue = searchInput?.value?.trim();
    if (searchValue) {
        params.set('q', searchValue);
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
