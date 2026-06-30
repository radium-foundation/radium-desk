import { parseRowHtml } from './service-case-row';

const getCurrentIncidentIds = (tbody) => Array.from(tbody.querySelectorAll('tr[id^="service-case-row-"]'))
    .map((row) => Number(row.id.replace('service-case-row-', '')));

const isLockedIncident = (incidentId, lockedIncidentIds) => lockedIncidentIds.includes(Number(incidentId));

const mergeServiceCaseRows = (card, rows, empty, emptyHtml, initTooltips, options = {}) => {
    const lockedIncidentIds = options.lockedIncidentIds ?? [];
    const onRowsUpdated = options.onRowsUpdated;
    const scrollContainer = card.querySelector('#dashboard-service-cases-scroll');
    const tbody = card.querySelector('#dashboard-service-cases-body');

    if (!scrollContainer || !tbody) {
        return;
    }

    const previousScrollTop = scrollContainer.scrollTop;
    const isAtTop = previousScrollTop <= 8;
    const currentIds = getCurrentIncidentIds(tbody);
    const nextIds = rows.map(({ incident_id: incidentId }) => incidentId);
    const newIncidentIds = nextIds.filter((incidentId) => !currentIds.includes(incidentId));
    const hasLockedRows = currentIds.some((incidentId) => isLockedIncident(incidentId, lockedIncidentIds));
    const replacedIncidentIds = [];

    if (empty) {
        if (hasLockedRows) {
            return;
        }

        if (emptyHtml) {
            tbody.innerHTML = emptyHtml;
        } else {
            tbody.innerHTML = `
                <tr id="dashboard-service-cases-empty-row">
                    <td colspan="${tbody.closest('table')?.querySelectorAll('thead th').length ?? 12}" class="text-center text-muted small py-3">
                        No service cases match this filter.
                    </td>
                </tr>
            `;
        }

        scrollContainer.scrollTop = previousScrollTop;

        return;
    }

    document.getElementById('dashboard-service-cases-empty-row')?.remove();

    rows.forEach(({ incident_id: incidentId, html }) => {
        if (isLockedIncident(incidentId, lockedIncidentIds)) {
            return;
        }

        const existingRow = document.getElementById(`service-case-row-${incidentId}`);

        if (existingRow) {
            const newRow = parseRowHtml(html);

            if (newRow && existingRow.outerHTML !== html) {
                existingRow.replaceWith(newRow);
                replacedIncidentIds.push(incidentId);
            }

            return;
        }

        const newRow = parseRowHtml(html);

        if (newRow) {
            tbody.appendChild(newRow);
            replacedIncidentIds.push(incidentId);

            return;
        }

        tbody.insertAdjacentHTML('beforeend', html);
        replacedIncidentIds.push(incidentId);
    });

    currentIds.forEach((incidentId) => {
        if (!nextIds.includes(incidentId) && !isLockedIncident(incidentId, lockedIncidentIds)) {
            document.getElementById(`service-case-row-${incidentId}`)?.remove();
        }
    });

    nextIds
        .map((incidentId) => document.getElementById(`service-case-row-${incidentId}`))
        .filter(Boolean)
        .forEach((row) => {
            tbody.appendChild(row);
        });

    if (isAtTop) {
        newIncidentIds.forEach((incidentId) => {
            const row = document.getElementById(`service-case-row-${incidentId}`);

            if (row) {
                row.classList.add('dashboard-row-fade-in');
                row.addEventListener('animationend', () => {
                    row.classList.remove('dashboard-row-fade-in');
                }, { once: true });
            }
        });
    }

    scrollContainer.scrollTop = previousScrollTop;
    initTooltips(tbody);

    if (replacedIncidentIds.length > 0) {
        onRowsUpdated?.(replacedIncidentIds);
    }
};

export { mergeServiceCaseRows };
