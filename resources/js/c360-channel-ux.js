import { showAppToast } from './core/toast';

export const textToHtml = (text) => {
    const escaped = String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return escaped
        .split(/\n{2,}/)
        .map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`)
        .join('');
};

export const preferDefaultSubject = (currentValue, defaultSubject, subjectTouched) => {
    if (subjectTouched) {
        return currentValue;
    }

    return defaultSubject || currentValue || '';
};

export const isNearBottom = (element, thresholdPx = 48) => {
    if (! element) {
        return true;
    }

    return element.scrollHeight - element.scrollTop - element.clientHeight <= thresholdPx;
};

export const canStartSend = ({ sending, body }) => {
    if (sending) {
        return false;
    }

    return String(body || '').trim() !== '';
};

export const notifyChannelSuccess = (message) => showAppToast(message, 'success');
export const notifyChannelFailure = (message) => showAppToast(message, 'danger');

export const highlightChannelTarget = (element, className = 'c360-email-bubble--highlight', ms = 1800) => {
    if (! element) {
        return;
    }

    element.classList.add(className);
    window.setTimeout(() => element.classList.remove(className), ms);
};
