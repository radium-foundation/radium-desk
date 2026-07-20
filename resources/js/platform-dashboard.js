const bindRefreshButton = (card) => {
    const button = card.querySelector('[data-platform-card-refresh]');

    if (!button || button.dataset.bound === 'true') {
        return;
    }

    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
        const url = card.dataset.refreshUrl;

        if (!url) {
            return;
        }

        button.disabled = true;
        button.classList.add('disabled');
        const icon = button.querySelector('i');
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
            const slot = card.closest('[data-platform-card-slot]') || card.parentElement;

            if (!slot || typeof payload.html !== 'string') {
                throw new Error('Invalid refresh payload');
            }

            slot.innerHTML = payload.html;
            const nextCard = slot.querySelector('[data-platform-card]');
            if (nextCard) {
                bindRefreshButton(nextCard);
            }
        } catch (error) {
            console.error(error);
            window.alert('Unable to refresh this card. Please try again.');
        } finally {
            button.disabled = false;
            button.classList.remove('disabled');
            if (icon) {
                icon.classList.remove('spin');
            }
        }
    });
};

export const initPlatformDashboard = () => {
    const root = document.getElementById('platform-dashboard-root');

    if (!root) {
        return;
    }

    root.querySelectorAll('[data-platform-card]').forEach((card) => {
        bindRefreshButton(card);
    });
};
