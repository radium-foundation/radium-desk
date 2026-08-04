/**
 * WhatsApp channel panel — consistent header chrome; opens existing wa.me / Interakt.
 */
export const openServiceCaseWhatsAppPanel = ({
    customer = 'Customer',
    owner = 'Unassigned',
    lastIn = '—',
    lastOut = '—',
    waUrl = '',
    interaktUrl = '',
} = {}) => {
    const modal = document.querySelector('[data-service-case-whatsapp-panel]');

    if (! modal || ! globalThis.bootstrap?.Modal) {
        return;
    }

    const setText = (selector, value) => {
        const el = modal.querySelector(selector);
        if (el) {
            el.textContent = value || '—';
        }
    };

    setText('[data-c360-whatsapp-meta-customer]', customer || 'Customer');
    setText('[data-c360-whatsapp-meta-owner]', owner || 'Unassigned');
    setText('[data-c360-whatsapp-meta-last-in]', lastIn || '—');
    setText('[data-c360-whatsapp-meta-last-out]', lastOut || '—');

    const waLink = modal.querySelector('[data-c360-whatsapp-wa-link]');
    const interaktLink = modal.querySelector('[data-c360-whatsapp-interakt-link]');

    if (waLink instanceof HTMLAnchorElement) {
        if (waUrl) {
            waLink.href = waUrl;
            waLink.hidden = false;
        } else {
            waLink.removeAttribute('href');
            waLink.hidden = true;
        }
    }

    if (interaktLink instanceof HTMLAnchorElement) {
        if (interaktUrl) {
            interaktLink.href = interaktUrl;
            interaktLink.hidden = false;
        } else {
            interaktLink.removeAttribute('href');
            interaktLink.hidden = true;
        }
    }

    globalThis.bootstrap.Modal.getOrCreateInstance(modal).show();
};

export const initServiceCaseWhatsAppPanel = () => {
    if (document.body.dataset.serviceCaseWhatsAppPanelBound === 'true') {
        return;
    }

    document.body.dataset.serviceCaseWhatsAppPanelBound = 'true';

    document.body.addEventListener('click', (event) => {
        const openButton = event.target.closest('[data-c360-whatsapp-open]');

        if (! (openButton instanceof HTMLElement)) {
            return;
        }

        event.preventDefault();
        openServiceCaseWhatsAppPanel({
            customer: openButton.dataset.c360WhatsappCustomer,
            owner: openButton.dataset.c360WhatsappOwner,
            lastIn: openButton.dataset.c360WhatsappLastIn,
            lastOut: openButton.dataset.c360WhatsappLastOut,
            waUrl: openButton.dataset.c360WhatsappWaUrl,
            interaktUrl: openButton.dataset.c360WhatsappInteraktUrl,
        });
    });
};
