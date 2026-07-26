import * as bootstrap from 'bootstrap';

export const showAppToast = (messageOrOptions, variant = 'success') => {
    const options = typeof messageOrOptions === 'object' && messageOrOptions !== null
        ? messageOrOptions
        : { message: messageOrOptions, variant };

    const {
        message,
        variant: resolvedVariant = variant,
        actions = [],
    } = options;

    let container = document.querySelector('.toast-container');

    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(container);
    }

    const toastElement = document.createElement('div');
    toastElement.className = `toast align-items-center text-bg-${resolvedVariant} border-0 app-toast`;
    toastElement.setAttribute('role', 'alert');
    toastElement.setAttribute('aria-live', 'assertive');
    toastElement.setAttribute('aria-atomic', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body app-toast-body';

    const messageNode = document.createElement('div');
    messageNode.className = 'app-toast-message';
    messageNode.style.whiteSpace = 'pre-line';
    messageNode.textContent = message ?? '';
    body.appendChild(messageNode);

    if (actions.length > 0) {
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'app-toast-actions';

        actions.forEach((action) => {
            const actionButton = document.createElement('button');
            actionButton.type = 'button';
            actionButton.className = 'app-toast-action';
            actionButton.textContent = action.label ?? 'Open';

            actionButton.addEventListener('click', () => {
                action.onClick?.();
                bootstrap.Toast.getOrCreateInstance(toastElement)?.hide();
            });

            actionsWrap.appendChild(actionButton);
        });

        body.appendChild(actionsWrap);
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex';
    wrapper.appendChild(body);

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn-close btn-close-white me-2 m-auto';
    closeButton.setAttribute('data-bs-dismiss', 'toast');
    closeButton.setAttribute('aria-label', 'Close');
    wrapper.appendChild(closeButton);

    toastElement.appendChild(wrapper);

    container.appendChild(toastElement);

    const toast = bootstrap.Toast.getOrCreateInstance(toastElement, {
        autohide: true,
        delay: actions.length > 0 ? 6500 : 4500,
    });

    toastElement.addEventListener('hidden.bs.toast', () => {
        toastElement.remove();
    });

    toast.show();
};

export const createCustomer360AwareToast = (drawerRef, buildSmartToastActions) => (message, variant = 'success') => {
    const drawerOpen = drawerRef.current?.isOpen?.() ?? false;
    const actions = drawerOpen ? buildSmartToastActions(message) : [];

    if (actions.length > 0) {
        showAppToast({ message, variant, actions });

        return;
    }

    showAppToast(message, variant);
};
