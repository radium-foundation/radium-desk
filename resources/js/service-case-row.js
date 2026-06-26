const parseRowHtml = (rowHtml) => {
    const template = document.createElement('template');
    template.innerHTML = rowHtml.trim();

    return template.content.firstElementChild;
};

export const createServiceCaseRowReplacer = ({ initTooltips, onRowReplaced }) => (incidentId, rowHtml) => {
    const existingRow = document.getElementById(`service-case-row-${incidentId}`);
    const newRow = parseRowHtml(rowHtml);

    if (!existingRow || !newRow) {
        return;
    }

    existingRow.replaceWith(newRow);
    initTooltips(newRow);
    onRowReplaced?.(Number(incidentId));
};

export { parseRowHtml };
