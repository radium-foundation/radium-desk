const DESK_ORDER_PATH = /^\/orders\/\d+(?:\/|$)/;

const ALLOWED_PORTAL_URLS = {
    mantratec: 'https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx',
    morpho: 'https://rdserviceonline.com/pages/172iserialvalidatenumber',
};

const isAllowedDeskHostname = (hostname) => {
    if (hostname === 'localhost' || hostname === '127.0.0.1') {
        return true;
    }

    if (hostname === 'desk.radiumbox.com') {
        return true;
    }

    return hostname.endsWith('.radiumdesk.test');
};

export const isAllowedDeskUrl = (url) => {
    if (typeof url !== 'string' || url === '') {
        return false;
    }

    try {
        const parsed = new URL(url);

        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return false;
        }

        if (!isAllowedDeskHostname(parsed.hostname)) {
            return false;
        }

        return DESK_ORDER_PATH.test(parsed.pathname);
    } catch (error) {
        return false;
    }
};

export const isAllowedPortalMessage = (message) => {
    if (!message || typeof message !== 'object') {
        return false;
    }

    if (message.type !== 'RDESK_OEM_WARRANTY_START' || message.version !== 1) {
        return false;
    }

    const provider = message.provider;
    const expectedPortal = ALLOWED_PORTAL_URLS[provider];

    if (!expectedPortal || message.portal_url !== expectedPortal) {
        return false;
    }

    if (typeof message.serial_number !== 'string' || message.serial_number.length < 3) {
        return false;
    }

    if (typeof message.handoff_token !== 'string' || message.handoff_token.length < 32) {
        return false;
    }

    if (typeof message.order_id !== 'number' || message.order_id <= 0) {
        return false;
    }

    return true;
};

export const isAllowedDeskSender = (sender) => {
    if (!sender?.url || typeof sender.url !== 'string') {
        return false;
    }

    return isAllowedDeskUrl(sender.url);
};

export const isAllowedProviderSenderUrl = (url) => {
    if (typeof url !== 'string' || url === '') {
        return false;
    }

    try {
        const parsed = new URL(url);

        return parsed.hostname === 'servico.mantratecapp.com'
            || parsed.hostname === 'rdserviceonline.com';
    } catch (error) {
        return false;
    }
};
