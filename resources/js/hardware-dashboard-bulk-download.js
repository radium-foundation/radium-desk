import { csrfToken } from './workspace/http';

const parseSummaryHeader = (headerValue) => {
    if (!headerValue) {
        return null;
    }
    try {
        const decoded = atob(headerValue);
        return JSON.parse(decoded);
    } catch {
        return null;
    }
};

const reasonForRow = (row) => row.download_failure || row.exclusion_reason || '';

const showReconciliation = (workspace, title, payload) => {
    const dialog = workspace.querySelector('[data-hardware-bulk-download-dialog]');
    if (!dialog || !payload) {
        return;
    }

    const titleEl = dialog.querySelector('[data-hardware-bulk-download-title]');
    const summaryEl = dialog.querySelector('[data-hardware-bulk-download-summary]');
    const body = dialog.querySelector('[data-hardware-bulk-download-rows]');

    if (titleEl) {
        titleEl.textContent = title;
    }

    if (summaryEl) {
        const parts = [
            `${payload.selected ?? 0} selected`,
            `${payload.included ?? 0} included`,
            `${payload.excluded ?? 0} excluded`,
            `${payload.failed ?? 0} failed`,
        ];
        if (payload.archive_ready === false) {
            parts.push('No archive produced — see details below.');
        }
        summaryEl.textContent = parts.join(' · ');
    }

    if (body) {
        body.replaceChildren();
        (payload.rows ?? []).forEach((row) => {
            const tr = document.createElement('tr');
            const reason = reasonForRow(row);
            tr.innerHTML = `<td>${row.source_id ?? row.fulfilment_id}</td><td>${row.result}</td><td>${reason}</td>`;
            body.append(tr);
        });
    }

    dialog.classList.remove('d-none');
    dialog.hidden = false;
};

const triggerBlobDownload = (blob, filename) => {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename || 'hardware-download.zip';
    anchor.click();
    URL.revokeObjectURL(url);
};

const filenameFromDisposition = (disposition) => {
    if (!disposition) {
        return null;
    }
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    return match ? match[1] : null;
};

export const initHardwareDashboardBulkDownload = (root = document) => {
    const workspace = root.querySelector('[data-hardware-workspace]');
    if (!workspace?.dataset.hardwareDownloadLabelsUrl) {
        return;
    }

    const dialog = workspace.querySelector('[data-hardware-bulk-download-dialog]');
    dialog?.querySelector('[data-hardware-bulk-download-close]')?.addEventListener('click', () => {
        dialog.classList.add('d-none');
        dialog.hidden = true;
    });

    const collectFulfilmentIds = () => {
        const ids = new Set();
        workspace.querySelectorAll('[data-hardware-select]:checked').forEach((input) => {
            const id = input.getAttribute('data-hardware-fulfilment-id')
                ?? input.closest('tr')?.getAttribute('data-hardware-fulfilment-id');
            if (id) {
                ids.add(Number(id));
            }
        });
        return [...ids];
    };

    const postDownload = async (url, title) => {
        const fulfilmentIds = collectFulfilmentIds();
        if (fulfilmentIds.length === 0) {
            showReconciliation(workspace, title, {
                selected: 0,
                included: 0,
                excluded: 0,
                failed: 0,
                archive_ready: false,
                rows: [{
                    fulfilment_id: 0,
                    source_id: '—',
                    result: 'excluded',
                    exclusion_reason: 'Select rows with a hardware fulfilment on this page.',
                }],
            });
            return;
        }

        const buttons = workspace.querySelectorAll(
            '[data-hardware-download-labels], [data-hardware-download-manifests], [data-hardware-download-package]',
        );
        buttons.forEach((button) => {
            button.disabled = true;
        });

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json, application/zip',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ fulfilment_ids: fulfilmentIds }),
            });

            const contentType = response.headers.get('Content-Type') ?? '';
            const summaryHeader = response.headers.get('X-Hardware-Bulk-Download-Summary');
            const summary = parseSummaryHeader(summaryHeader);

            if (contentType.includes('application/json')) {
                const payload = await response.json();
                showReconciliation(workspace, title, payload);
                return;
            }

            if (!response.ok) {
                showReconciliation(workspace, title, summary ?? {
                    selected: fulfilmentIds.length,
                    included: 0,
                    excluded: 0,
                    failed: fulfilmentIds.length,
                    archive_ready: false,
                    rows: [],
                });
                return;
            }

            const blob = await response.blob();
            const filename = filenameFromDisposition(response.headers.get('Content-Disposition'))
                ?? 'hardware-download.zip';
            triggerBlobDownload(blob, filename);
            if (summary) {
                showReconciliation(workspace, title, summary);
            }
        } catch {
            showReconciliation(workspace, title, {
                selected: fulfilmentIds.length,
                included: 0,
                excluded: 0,
                failed: fulfilmentIds.length,
                archive_ready: false,
                rows: [{
                    source_id: '—',
                    result: 'failed',
                    download_failure: 'Download request failed.',
                }],
            });
        } finally {
            workspace.dispatchEvent(new CustomEvent('hardware:selection-changed'));
        }
    };

    workspace.querySelector('[data-hardware-download-labels]')?.addEventListener('click', () => {
        postDownload(workspace.dataset.hardwareDownloadLabelsUrl, 'Download existing labels');
    });
    workspace.querySelector('[data-hardware-download-manifests]')?.addEventListener('click', () => {
        postDownload(workspace.dataset.hardwareDownloadManifestsUrl, 'Download existing manifests');
    });
    workspace.querySelector('[data-hardware-download-package]')?.addEventListener('click', () => {
        postDownload(workspace.dataset.hardwareDownloadPackageUrl, 'Download complete package');
    });
};