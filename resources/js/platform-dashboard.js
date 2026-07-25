const applyCardRefreshPayload = (card, payload) => {
    const slot = card.closest('[data-platform-card-slot]') || card.parentElement;

    if (!slot || typeof payload.html !== 'string') {
        throw new Error('Invalid refresh payload');
    }

    slot.innerHTML = payload.html;

    const nextCard = slot.querySelector('[data-platform-card]');

    if (nextCard) {
        bindRefreshButton(nextCard);
    }

    return nextCard ?? card;
};

const refreshPlatformCard = async (card, { surfaceErrors = true } = {}) => {
    const url = card.dataset.refreshUrl;

    if (!url || document.hidden) {
        return false;
    }

    const button = card.querySelector('[data-platform-card-refresh]');
    const icon = button?.querySelector('i');

    if (button instanceof HTMLButtonElement) {
        button.disabled = true;
        button.classList.add('disabled');
    }

    if (icon) {
        icon.classList.add('spin');
    }

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Refresh failed (${response.status})`);
        }

        const payload = await response.json();
        applyCardRefreshPayload(card, payload);

        return true;
    } catch (error) {
        console.error(error);

        if (surfaceErrors) {
            window.alert('Unable to refresh this card. Please try again.');
        }

        return false;
    } finally {
        if (button instanceof HTMLButtonElement) {
            button.disabled = false;
            button.classList.remove('disabled');
        }

        if (icon) {
            icon.classList.remove('spin');
        }
    }
};

const bindRefreshButton = (card) => {
    const button = card.querySelector('[data-platform-card-refresh]');

    if (!button || button.dataset.bound === 'true') {
        return;
    }

    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
        await refreshPlatformCard(card, { surfaceErrors: true });
    });
};

let pollIntervalId = null;
let pollPageRoot = null;
let pollIntervalMs = 0;
let pollVisibilityHandler = null;

const refreshableCards = (root) => (
    [...root.querySelectorAll('[data-platform-card][data-refresh-url]')]
);

const refreshAllPlatformCards = async (root, { surfaceErrors = false } = {}) => {
    if (document.hidden) {
        return;
    }

    await Promise.all(
        refreshableCards(root).map((card) => refreshPlatformCard(card, { surfaceErrors })),
    );
};

export const stopPlatformPolling = () => {
    if (pollIntervalId === null) {
        return;
    }

    window.clearInterval(pollIntervalId);
    pollIntervalId = null;
};

const bindPlatformPollingVisibilityListener = () => {
    if (pollVisibilityHandler !== null) {
        return;
    }

    pollVisibilityHandler = () => {
        if (document.visibilityState === 'hidden') {
            stopPlatformPolling();

            return;
        }

        if (pollPageRoot === null) {
            return;
        }

        refreshAllPlatformCards(pollPageRoot);
        startPlatformPolling(pollPageRoot, pollIntervalMs);
    };

    document.addEventListener('visibilitychange', pollVisibilityHandler);
};

export const startPlatformPolling = (root, intervalMs) => {
    pollPageRoot = root;
    pollIntervalMs = intervalMs;

    bindPlatformPollingVisibilityListener();

    if (intervalMs <= 0) {
        stopPlatformPolling();

        return;
    }

    if (document.visibilityState === 'hidden') {
        stopPlatformPolling();

        return;
    }

    if (pollIntervalId !== null) {
        return;
    }

    pollIntervalId = window.setInterval(() => {
        refreshAllPlatformCards(root);
    }, intervalMs);
};

export const initPlatformDashboard = () => {
    const root = document.getElementById('platform-dashboard-root');

    if (!root) {
        return;
    }

    root.querySelectorAll('[data-platform-card]').forEach((card) => {
        bindRefreshButton(card);
    });

    const intervalMs = Number(root.dataset.pollIntervalSeconds ?? 0) * 1000;

    if (intervalMs > 0) {
        startPlatformPolling(root, intervalMs);
    }
};
