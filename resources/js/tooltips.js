import * as bootstrap from 'bootstrap';

export const initTooltips = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existing = bootstrap.Tooltip.getInstance(element);

        if (existing) {
            existing.dispose();
        }

        bootstrap.Tooltip.getOrCreateInstance(element);
    });
};
