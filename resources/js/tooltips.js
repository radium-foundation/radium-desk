import * as bootstrap from 'bootstrap';

const tooltipOptionsFor = (element) => {
    const options = {};

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

const premiumTooltipTitleFor = (element) => {
    if (! element.hasAttribute('data-dashboard-tooltip')) {
        return null;
    }

    const template = element.nextElementSibling?.matches('.dashboard-tooltip-template')
        ? element.nextElementSibling
        : null;

    if (! template) {
        return null;
    }

    return template.innerHTML.trim();
};

export const initTooltips = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existing = bootstrap.Tooltip.getInstance(element);

        if (existing) {
            existing.dispose();
        }

        const options = tooltipOptionsFor(element);
        const premiumTitle = premiumTooltipTitleFor(element);

        if (premiumTitle !== null) {
            options.html = true;
            options.title = premiumTitle;
        } else if (element.getAttribute('data-bs-html') === 'true') {
            options.html = true;
        }

        bootstrap.Tooltip.getOrCreateInstance(element, options);
    });
};
