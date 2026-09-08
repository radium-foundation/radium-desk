const selectedLabel = (count) => (count === 1 ? '1 selected' : `${count} selected`);

export const initHardwareDashboardSelection = (root = document) => {
    const workspace = root.querySelector('[data-hardware-workspace]');
    if (!workspace) {
        return;
    }

    const bar = workspace.querySelector('[data-hardware-selection-bar]');
    const countLabel = workspace.querySelector('[data-hardware-selection-count]');
    const openSelected = workspace.querySelector('[data-hardware-open-selected]');
    const clearSelected = workspace.querySelector('[data-hardware-clear-selected]');
    const selectAll = workspace.querySelector('[data-hardware-select-all]');

    const visibleBoxes = () => Array.from(workspace.querySelectorAll('[data-hardware-select]'))
        .filter((input) => !input.closest('.dashboard-case-row--filtered-out'));

    const update = () => {
        const boxes = visibleBoxes();
        const checked = boxes.filter((input) => input.checked);
        boxes.forEach((input) => {
            input.closest('tr')?.classList.toggle('dashboard-case-row--selected', input.checked);
        });
        if (selectAll) {
            selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
        if (bar) {
            const active = checked.length > 0;
            bar.classList.toggle('d-none', !active);
            bar.hidden = !active;
        }
        if (countLabel) {
            countLabel.textContent = selectedLabel(checked.length);
        }
        if (openSelected) {
            openSelected.disabled = checked.length === 0;
        }
    };

    workspace.addEventListener('change', (event) => {
        if (event.target.matches('[data-hardware-select-all]')) {
            visibleBoxes().forEach((input) => {
                input.checked = event.target.checked;
            });
        }
        if (event.target.matches('[data-hardware-select], [data-hardware-select-all]')) {
            update();
        }
    });

    openSelected?.addEventListener('click', () => {
        const first = visibleBoxes().find((input) => input.checked)?.closest('tr[data-incident-id]');
        if (!first) {
            return;
        }

        first.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    clearSelected?.addEventListener('click', () => {
        visibleBoxes().forEach((input) => {
            input.checked = false;
        });
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        update();
    });

    workspace.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-hardware-product-detail]');
        if (!trigger) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        const cell = trigger.closest('td');
        const popover = cell?.querySelector('.dashboard-hardware-product-popover');
        if (!popover) {
            return;
        }

        const open = popover.hasAttribute('hidden');
        popover.toggleAttribute('hidden', !open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    update();
};
