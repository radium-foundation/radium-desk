import * as bootstrap from 'bootstrap';

export const initTooltips = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existing = bootstrap.Tooltip.getInstance(element);

        if (existing) {
            existing.dispose();
        }

        const container = element.getAttribute('data-bs-container') ?? undefined;

        bootstrap.Tooltip.getOrCreateInstance(element, {
            container: container === 'body' ? document.body : undefined,
            boundary: element.getAttribute('data-bs-boundary') ?? undefined,
            customClass: element.getAttribute('data-bs-custom-class') ?? undefined,
            html: element.getAttribute('data-bs-html') === 'true',
            placement: element.getAttribute('data-bs-placement') ?? undefined,
        });
    });
};
