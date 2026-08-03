const contentUrl = (messageId) => `/dashboard/incoming-email-messages/${messageId}/content`;
const replyContextUrl = (messageId) => `/dashboard/incoming-email-messages/${messageId}/reply-context`;
const replyPreviewUrl = (messageId) => `/dashboard/incoming-email-messages/${messageId}/reply-preview`;
const replySendUrl = (messageId) => `/dashboard/incoming-email-messages/${messageId}/reply`;
const attachmentUrl = (messageId, attachmentId) => `/dashboard/incoming-email-messages/${messageId}/attachments/${encodeURIComponent(attachmentId)}`;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

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
        replyToggle: modal.querySelector('[data-incoming-email-reply-toggle]'),
        replySend: modal.querySelector('[data-incoming-email-reply-send]'),
        replyPanel: modal.querySelector('[data-incoming-email-reply-panel]'),
        replyTemplate: modal.querySelector('[data-incoming-email-reply-template]'),
        replySubject: modal.querySelector('[data-incoming-email-reply-subject]'),
        replyBody: modal.querySelector('[data-incoming-email-reply-body]'),
        replyError: modal.querySelector('[data-incoming-email-reply-error]'),
        replySuccess: modal.querySelector('[data-incoming-email-reply-success]'),
    };
};

const setModalState = (elements, state) => {
    elements.loading.hidden = state !== 'loading';
    elements.error.hidden = state !== 'error';
    elements.body.hidden = state !== 'ready';
    elements.attachments.hidden = state !== 'ready' || elements.attachments.children.length === 0;
};

const resetReplyUi = (elements) => {
    if (! elements.replyPanel) {
        return;
    }

    elements.replyPanel.hidden = true;
    elements.replyToggle.hidden = true;
    elements.replySend.hidden = true;
    elements.replyError.hidden = true;
    elements.replySuccess.hidden = true;
    elements.replyError.textContent = '';
    elements.replySuccess.textContent = '';
    elements.replyTemplate.innerHTML = '';
    elements.replySubject.value = '';
    elements.replyBody.value = '';
    elements.modal.dataset.incomingEmailMessageId = '';
    elements.modal.dataset.replyReady = 'false';
};

const populateTemplates = (select, templates) => {
    select.innerHTML = '';

    (templates || []).forEach((template) => {
        const option = document.createElement('option');
        option.value = template.key;
        option.textContent = template.label;
        select.appendChild(option);
    });
};

const htmlToEditableText = (html) => {
    const host = document.createElement('div');
    host.innerHTML = html || '';

    return host.innerText.trim();
};

const textToHtml = (text) => {
    const escaped = String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return escaped
        .split(/\n{2,}/)
        .map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`)
        .join('');
};

const enableReplyUi = async (elements, messageId, contentPayload) => {
    if (! contentPayload?.can_reply) {
        return;
    }

    const response = await fetch(replyContextUrl(messageId), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (! response.ok) {
        return;
    }

    const context = await response.json();

    if (! context.can_reply) {
        return;
    }

    elements.modal.dataset.incomingEmailMessageId = String(messageId);
    elements.modal.dataset.replyReady = 'true';
    elements.replyToggle.hidden = false;
    populateTemplates(elements.replyTemplate, context.templates);
    elements.replySubject.value = context.default_subject || '';
    elements.replyBody.value = '';
};

const loadTemplatePreview = async (elements, messageId, templateKey) => {
    elements.replyError.hidden = true;
    elements.replySuccess.hidden = true;

    const response = await fetch(replyPreviewUrl(messageId), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ template_key: templateKey }),
    });

    if (! response.ok) {
        elements.replyError.textContent = 'Unable to load template preview.';
        elements.replyError.hidden = false;

        return;
    }

    const preview = await response.json();
    elements.replySubject.value = preview.subject || '';
    elements.replyBody.value = htmlToEditableText(preview.body_html || '');
};

const sendReply = async (elements) => {
    const messageId = elements.modal.dataset.incomingEmailMessageId;

    if (! messageId) {
        return;
    }

    elements.replyError.hidden = true;
    elements.replySuccess.hidden = true;
    elements.replySend.disabled = true;

    try {
        const response = await fetch(replySendUrl(messageId), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                subject: elements.replySubject.value,
                body_html: textToHtml(elements.replyBody.value),
                template_key: elements.replyTemplate.value || 'blank',
            }),
        });

        const payload = await response.json().catch(() => ({}));

        if (! response.ok) {
            elements.replyError.textContent = payload.error || payload.message || 'Failed to send reply.';
            elements.replyError.hidden = false;

            return;
        }

        elements.replySuccess.textContent = 'Reply sent. It will appear on the timeline immediately.';
        elements.replySuccess.hidden = false;
        elements.replyPanel.hidden = true;
        elements.replySend.hidden = true;
        elements.replyToggle.textContent = 'Reply';
    } catch (error) {
        elements.replyError.textContent = 'Failed to send reply. Please try again.';
        elements.replyError.hidden = false;
    } finally {
        elements.replySend.disabled = false;
    }
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
    resetReplyUi(elements);
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
        await enableReplyUi(elements, messageId, payload);
    } catch (error) {
        elements.error.textContent = 'Unable to load the full email. Please try again.';
        setModalState(elements, 'error');
    }
};

export const initIncomingEmailModal = (root = document) => {
    const bindTarget = root?.dataset ? root : document.body;

    if (bindTarget.dataset.incomingEmailModalBound === 'true') {
        return;
    }

    bindTarget.dataset.incomingEmailModalBound = 'true';

    bindTarget.addEventListener('click', (event) => {
        const button = event.target.closest('[data-incoming-email-read-full]');

        if (! button || ! bindTarget.contains(button)) {
            return;
        }

        event.preventDefault();

        const messageId = button.dataset.incomingEmailReadFull;

        if (! messageId) {
            return;
        }

        openIncomingEmailModal(messageId);
    });

    const elements = getModalElements();

    if (! elements) {
        return;
    }

    elements.replyToggle?.addEventListener('click', () => {
        const opening = elements.replyPanel.hidden;
        elements.replyPanel.hidden = ! opening;
        elements.replySend.hidden = ! opening;
        elements.replyToggle.textContent = opening ? 'Cancel reply' : 'Reply';
        elements.replyError.hidden = true;
        elements.replySuccess.hidden = true;
    });

    elements.replyTemplate?.addEventListener('change', async () => {
        const messageId = elements.modal.dataset.incomingEmailMessageId;
        const templateKey = elements.replyTemplate.value;

        if (! messageId || ! templateKey) {
            return;
        }

        await loadTemplatePreview(elements, messageId, templateKey);
    });

    elements.replySend?.addEventListener('click', async () => {
        await sendReply(elements);
    });
};
