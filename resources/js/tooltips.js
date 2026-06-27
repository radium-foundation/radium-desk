import * as bootstrap from 'bootstrap';

const tooltipOptionsFor = (element) => {
    const options = {};

    if (element.getAttribute('data-bs-html') === 'true') {
        options.html = true;
    }

    if (element.getAttribute('data-bs-container') === 'body') {
        options.container = document.body;
    }

    const boundary = element.getAttribute('data-bs-boundary');

    if (boundary) {
        options.boundary = boundary;
    }

    const customClass = element.getAttribute('data-bs-custom-class');

    if (customClass) {
        options.customClass = customClass;
    }

    const placement = element.getAttribute('data-bs-placement');

    if (placement) {
        options.placement = placement;
    }

    return options;
};

export const initTooltips = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existing = bootstrap.Tooltip.getInstance(element);

        if (existing) {
            existing.dispose();
        }

        bootstrap.Tooltip.getOrCreateInstance(element, tooltipOptionsFor(element));
    });
};
