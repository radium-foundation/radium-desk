const contentUrl = (messageId) => `/dashboard/incoming-email-messages/${messageId}/content`;
const attachmentUrl = (messageId, attachmentId) => `/dashboard/incoming-email-messages/${messageId}/attachments/${encodeURIComponent(attachmentId)}`;

const formatBytes = (bytes) => {
    if (! Number.isFinite(bytes) || bytes <= 0) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const renderEmailBody = (container, payload) => {
    container.innerHTML = '';

    if (payload.body_html) {
        const htmlHost = document.createElement('div');
        htmlHost.className = 'c360-incoming-email-html';
        htmlHost.innerHTML = payload.body_html;
        container.appendChild(htmlHost);

        return;
    }

    if (payload.body_text) {
        const textHost = document.createElement('pre');
        textHost.className = 'c360-incoming-email-text mb-0';
        textHost.textContent = payload.body_text;
        container.appendChild(textHost);

        return;
    }

    container.textContent = 'No email body available.';
};

const renderAttachments = (list, messageId, attachments) => {
    list.innerHTML = '';

    if (! Array.isArray(attachments) || attachments.length === 0) {
        list.hidden = true;

        return;
    }

    attachments.forEach((attachment) => {
        const attachmentId = attachment?.attachment_id;

        if (! attachmentId) {
            return;
        }

        const item = document.createElement('li');
        const link = document.createElement('a');
        const filename = attachment.filename || 'attachment';
        const sizeLabel = formatBytes(attachment.size);

        link.href = attachmentUrl(messageId, attachmentId);
        link.className = 'c360-incoming-email-attachment-link';
        link.textContent = sizeLabel !== ''
            ? `${filename} (${sizeLabel})`
            : filename;
        link.setAttribute('download', filename);

        item.appendChild(link);
        list.appendChild(item);
    });

    list.hidden = list.children.length === 0;
};

const getModalElements = () => {
    const modal = document.querySelector('[data-incoming-email-modal]');

    if (! modal) {
        return null;
    }

    return {
        modal,
        subject: modal.querySelector('[data-incoming-email-modal-subject]'),
        meta: modal.querySelector('[data-incoming-email-modal-meta]'),
        loading: modal.querySelector('[data-incoming-email-modal-loading]'),
        error: modal.querySelector('[data-incoming-email-modal-error]'),
        body: modal.querySelector('[data-incoming-email-modal-body]'),
        attachments: modal.querySelector('[data-incoming-email-modal-attachments]'),
    };
};

const setModalState = (elements, state) => {
    elements.loading.hidden = state !== 'loading';
    elements.error.hidden = state !== 'error';
    elements.body.hidden = state !== 'ready';
    elements.attachments.hidden = state !== 'ready' || elements.attachments.children.length === 0;
};

const openIncomingEmailModal = async (messageId) => {
    const elements = getModalElements();

    if (! elements || ! globalThis.bootstrap?.Modal) {
        return;
    }

    const modalInstance = globalThis.bootstrap.Modal.getOrCreateInstance(elements.modal);

    elements.subject.textContent = 'Loading email…';
    elements.meta.textContent = '';
    elements.error.textContent = '';
    elements.body.innerHTML = '';
    elements.attachments.innerHTML = '';
    setModalState(elements, 'loading');

    modalInstance.show();

    try {
        const response = await fetch(contentUrl(messageId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const sender = payload.from_name
            ? `${payload.from_name} <${payload.from_email}>`
            : (payload.from_email ?? 'Unknown sender');

        elements.subject.textContent = payload.subject || 'Incoming Email';
        elements.meta.textContent = [sender, payload.received_at].filter(Boolean).join(' · ');
        renderEmailBody(elements.body, payload);
        renderAttachments(elements.attachments, messageId, payload.attachments);
        setModalState(elements, 'ready');
    } catch (error) {
        elements.error.textContent = 'Unable to load the full email. Please try again.';
        setModalState(elements, 'error');
    }
};

export const initIncomingEmailModal = (root = document) => {
    if (root.dataset.incomingEmailModalBound === 'true') {
        return;
    }

    root.dataset.incomingEmailModalBound = 'true';

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-incoming-email-read-full]');

        if (! button || ! root.contains(button)) {
            return;
        }

        event.preventDefault();

        const messageId = button.dataset.incomingEmailReadFull;

        if (! messageId) {
            return;
        }

        openIncomingEmailModal(messageId);
    });
};
