const getCurrentIncidentIds = (tbody) => Array.from(tbody.querySelectorAll('tr[id^="service-case-row-"]'))
    .map((row) => Number(row.id.replace('service-case-row-', '')));

const mergeServiceCaseRows = (card, rows, empty, emptyHtml, initTooltips) => {
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

    if (empty) {
        tbody.innerHTML = `
            <tr id="dashboard-service-cases-empty-row">
                <td colspan="${tbody.closest('table')?.querySelectorAll('thead th').length ?? 12}" class="text-center text-muted small py-3">
                    No service cases match this filter.
                </td>
            </tr>
        `;
        scrollContainer.scrollTop = previousScrollTop;

        return;
    }

    document.getElementById('dashboard-service-cases-empty-row')?.remove();

    rows.forEach(({ incident_id: incidentId, html }) => {
        const existingRow = document.getElementById(`service-case-row-${incidentId}`);

        if (existingRow) {
            if (existingRow.outerHTML !== html) {
                existingRow.outerHTML = html;
            }

            return;
        }

        tbody.insertAdjacentHTML('beforeend', html);
    });

    currentIds.forEach((incidentId) => {
        if (!nextIds.includes(incidentId)) {
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
};

export { mergeServiceCaseRows };
