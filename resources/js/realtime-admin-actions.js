const showRealtimeAdminMessage = (element, message, isError = false) => {
    element.textContent = message;
    element.classList.remove('d-none', 'text-danger', 'text-success');
    element.classList.add(isError ? 'text-danger' : 'text-success');
};

export const initRealtimeAdminActions = () => {
    const root = document.getElementById('realtime-settings-card');

    if (!root) {
        return;
    }

    const messageEl = root.querySelector('[data-realtime-admin-message]');
    const testButton = root.querySelector('[data-realtime-test]');
    const forceReconnectButton = root.querySelector('[data-realtime-force-reconnect]');
    const resetStatusButton = root.querySelector('[data-realtime-reset-status]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const postJson = async (url) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message ?? 'Request failed.');
        }

        return data;
    };

    testButton?.addEventListener('click', async () => {
        if (!messageEl) {
            return;
        }

        showRealtimeAdminMessage(messageEl, 'Testing realtime connection…');

        try {
            const data = await postJson(testButton.dataset.url);
            showRealtimeAdminMessage(messageEl, data.message ?? 'Connection test passed.');
        } catch (error) {
            showRealtimeAdminMessage(messageEl, error.message ?? 'Connection test failed.', true);
        }
    });

    forceReconnectButton?.addEventListener('click', async () => {
        if (!messageEl) {
            return;
        }

        showRealtimeAdminMessage(messageEl, 'Requesting force reconnect…');

        try {
            const data = await postJson(forceReconnectButton.dataset.url);
            showRealtimeAdminMessage(messageEl, data.message ?? 'Force reconnect requested.');
        } catch (error) {
            showRealtimeAdminMessage(messageEl, error.message ?? 'Force reconnect failed.', true);
        }
    });

    resetStatusButton?.addEventListener('click', async () => {
        if (!messageEl) {
            return;
        }

        showRealtimeAdminMessage(messageEl, 'Resetting connection status…');

        try {
            const response = await fetch(resetStatusButton.dataset.url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json, text/html',
                    'X-CSRF-TOKEN': csrfToken ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Request failed.');
            }

            window.location.reload();
        } catch (error) {
            showRealtimeAdminMessage(messageEl, error.message ?? 'Reset failed.', true);
        }
    });
};
