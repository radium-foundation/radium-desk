const SLUGS_ELEMENT_ID = 'workspace-context-slugs';

let configuredSlugs = null;
let workspaceContextConstants = null;

const parseConfiguredSlugs = () => {
    const element = document.getElementById(SLUGS_ELEMENT_ID);

    if (!element?.textContent) {
        return null;
    }

    try {
        return JSON.parse(element.textContent);
    } catch (error) {
        return null;
    }
};

const buildConstants = (slugs) => Object.freeze({
    Dashboard: slugs.Dashboard,
    ServiceCase: slugs.ServiceCase,
    Order: slugs.Order,
    Customer: slugs.Customer,
    Mobile: slugs.Mobile,
    Api: slugs.Api,
    Ai: slugs.Ai,
});

export const configureWorkspaceContext = (slugs) => {
    configuredSlugs = Object.freeze({ ...slugs });
    workspaceContextConstants = buildConstants(configuredSlugs);
};

export const isWorkspaceContextConfigured = () => workspaceContextConstants !== null;

export const getWorkspaceContextConstants = () => workspaceContextConstants ?? Object.freeze({});

const ensureConfigured = () => {
    if (workspaceContextConstants !== null) {
        return workspaceContextConstants;
    }

    const parsed = parseConfiguredSlugs();

    if (parsed) {
        configureWorkspaceContext(parsed);
    }

    return workspaceContextConstants;
};

export const isValidWorkspaceContext = (value) => {
    const constants = ensureConfigured();

    if (!constants || !value) {
        return false;
    }

    return Object.values(constants).includes(value);
};

export const resolvePageWorkspaceContext = () => {
    const root = document.querySelector('[data-workspace-context]');

    return root?.dataset.workspaceContext ?? null;
};

export const getActiveWorkspaceContext = (host = document.querySelector('[data-workspace-modal-host]')) => (
    host?.dataset.workspaceActiveContext ?? null
);

export const setActiveWorkspaceContext = (host, context) => {
    if (!host) {
        return;
    }

    if (context) {
        host.dataset.workspaceActiveContext = context;
    } else {
        delete host.dataset.workspaceActiveContext;
    }
};

export const appendWorkspaceContextQuery = (url, context) => {
    if (!context) {
        return url;
    }

    const parsedUrl = new URL(url, window.location.origin);
    parsedUrl.searchParams.set('context', context);

    return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
};

export const initWorkspaceContext = () => {
    ensureConfigured();
};
