import { openIncomingEmailModal } from './incoming-email-modal';
import {
    buildThreadQuery,
    canStartSend,
    highlightChannelTarget,
    isNearBottom,
    newestCursorFromMessages,
    notifyChannelFailure,
    notifyChannelSuccess,
    oldestCursorFromMessages,
    preferDefaultSubject,
    textToHtml,
} from './service-case-email-workspace-helpers';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const replySendUrl = (messageId) => `/dashboard/incoming-email-messages/${messageId}/reply`;
const POLL_MS = 20000;

const formatWhen = (iso) => {
    if (! iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const state = {
    threadUrl: null,
    readUrl: null,
    sending: false,
    subjectTouched: false,
    defaultSubject: '',
    messages: [],
    hasMoreOlder: false,
    loadingOlder: false,
    pollTimer: null,
    focusIncomingId: null,
};

const getElements = () => {
    const modal = document.querySelector('[data-service-case-email-modal]');

    if (! modal) {
        return null;
    }

    return {
        modal,
        subtitle: modal.querySelector('[data-sc-email-modal-subtitle]'),
        metaCustomer: modal.querySelector('[data-sc-email-meta-customer]'),
        metaOwner: modal.querySelector('[data-sc-email-meta-owner]'),
        lastIn: modal.querySelector('[data-sc-email-last-in]'),
        lastOut: modal.querySelector('[data-sc-email-last-out]'),
        loading: modal.querySelector('[data-sc-email-loading]'),
        error: modal.querySelector('[data-sc-email-error]'),
        thread: modal.querySelector('[data-sc-email-thread]'),
        threadList: modal.querySelector('[data-sc-email-thread-list]'),
        loadOlder: modal.querySelector('[data-sc-email-load-older]'),
        empty: modal.querySelector('[data-sc-email-empty]'),
        composer: modal.querySelector('[data-sc-email-composer]'),
        replyHint: modal.querySelector('[data-sc-email-reply-hint]'),
        replyToggle: modal.querySelector('[data-sc-email-reply-toggle]'),
        replyCancel: modal.querySelector('[data-sc-email-reply-cancel]'),
        subject: modal.querySelector('[data-sc-email-subject]'),
        body: modal.querySelector('[data-sc-email-body]'),
        send: modal.querySelector('[data-sc-email-send]'),
        sendError: modal.querySelector('[data-sc-email-send-error]'),
    };
};

const clearUnreadBadge = () => {
    document.querySelectorAll('[data-c360-email-unread-badge]').forEach((badge) => {
        badge.remove();
    });
};

const setComposerOpen = (elements, open) => {
    elements.composer.hidden = ! open;
    elements.send.hidden = ! open;
    elements.replyCancel.hidden = ! open;
    elements.replyToggle.hidden = open || elements.replyToggle.dataset.available !== 'true';
    elements.sendError.hidden = true;
    elements.sendError.textContent = '';
};

const resetComposer = (elements) => {
    elements.subject.value = '';
    elements.body.value = '';
    elements.sendError.hidden = true;
    elements.sendError.textContent = '';
    elements.replyHint.hidden = true;
    elements.replyHint.textContent = '';
    elements.replyToggle.dataset.available = 'false';
    elements.replyToggle.hidden = true;
    elements.modal.dataset.replyToId = '';
    state.subjectTouched = false;
    state.defaultSubject = '';
    state.sending = false;
    elements.send.disabled = false;
    setComposerOpen(elements, false);
};

const createBubble = (message) => {
    const bubble = document.createElement('article');
    const inbound = message.direction === 'inbound';
    bubble.className = `c360-email-bubble ${inbound ? 'c360-email-bubble--in' : 'c360-email-bubble--out'}`;
    bubble.dataset.direction = message.direction;
    bubble.dataset.messageId = String(message.id);

    const meta = document.createElement('div');
    meta.className = 'c360-email-bubble-meta';
    const who = inbound
        ? (message.from_name || message.from_email || 'Customer')
        : (message.to_email ? `To ${message.to_email}` : 'Outbound');
    meta.textContent = [who, message.status_label, formatWhen(message.occurred_at)]
        .filter(Boolean)
        .join(' · ');

    const subject = document.createElement('div');
    subject.className = 'c360-email-bubble-subject';
    subject.textContent = message.subject || '(no subject)';

    bubble.appendChild(meta);
    bubble.appendChild(subject);

    if (message.preview) {
        const preview = document.createElement('div');
        preview.className = 'c360-email-bubble-preview';
        preview.textContent = message.preview;
        bubble.appendChild(preview);
    }

    if (inbound && message.can_open) {
        const openBtn = document.createElement('button');
        openBtn.type = 'button';
        openBtn.className = 'btn btn-link btn-sm px-0 c360-email-bubble-open';
        openBtn.dataset.scEmailOpenFull = String(message.id);
        openBtn.textContent = 'Read full email';
        bubble.appendChild(openBtn);
    }

    return bubble;
};

const renderThreadList = (elements, messages, { prepend = false } = {}) => {
    if (! prepend) {
        elements.threadList.innerHTML = '';
    }

    const fragment = document.createDocumentFragment();
    messages.forEach((message) => {
        if (elements.threadList.querySelector(`[data-message-id="${message.id}"][data-direction="${message.direction}"]`)) {
            return;
        }

        fragment.appendChild(createBubble(message));
    });

    if (prepend) {
        elements.threadList.prepend(fragment);
    } else {
        elements.threadList.appendChild(fragment);
    }
};

const updateLoadOlder = (elements) => {
    if (! elements.loadOlder) {
        return;
    }

    elements.loadOlder.hidden = ! state.hasMoreOlder;
};

const updateHeaderStats = (elements, payload) => {
    if (elements.metaCustomer) {
        elements.metaCustomer.textContent = payload.customer_label || 'Customer';
    }

    if (elements.metaOwner) {
        elements.metaOwner.textContent = payload.owner_label || 'Unassigned';
    }

    if (elements.lastIn) {
        elements.lastIn.textContent = payload.last_customer_email_at
            ? formatWhen(payload.last_customer_email_at)
            : '—';
    }

    if (elements.lastOut) {
        elements.lastOut.textContent = payload.last_outgoing_email_at
            ? formatWhen(payload.last_outgoing_email_at)
            : '—';
    }
};

const applyReplyState = (elements, payload) => {
    if (! payload.can_reply || ! payload.reply_to_incoming_email_message_id) {
        elements.replyToggle.dataset.available = 'false';
        elements.replyToggle.hidden = true;

        if (payload.reply_reason && Array.isArray(payload.messages) && payload.messages.length > 0) {
            elements.replyHint.textContent = 'Reply is not available for your role on this conversation.';
            elements.replyHint.hidden = false;
            elements.composer.hidden = false;
        }

        return;
    }

    elements.modal.dataset.replyToId = String(payload.reply_to_incoming_email_message_id);
    state.defaultSubject = payload.default_subject || '';
    elements.subject.value = preferDefaultSubject(
        elements.subject.value,
        state.defaultSubject,
        state.subjectTouched,
    );
    elements.replyToggle.dataset.available = 'true';
    elements.replyToggle.hidden = ! elements.composer.hidden;
};

const highlightMessage = (elements, messageId) => {
    if (! messageId) {
        return;
    }

    const target = elements.threadList.querySelector(`[data-message-id="${messageId}"][data-direction="inbound"]`)
        || elements.threadList.querySelector(`[data-message-id="${messageId}"]`);

    if (! target) {
        return;
    }

    target.scrollIntoView({ block: 'center' });
    highlightChannelTarget(target);
};

const markThreadRead = async (payload) => {
    if (! state.readUrl || ! payload?.latest_inbound_id) {
        clearUnreadBadge();

        return;
    }

    try {
        await fetch(state.readUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ latest_inbound_id: payload.latest_inbound_id }),
        });
    } catch {
        // Best-effort.
    }

    clearUnreadBadge();
};

export const refreshCustomer360TimelineAfterEmail = async () => {
    const section = document.querySelector('[data-customer-360-timeline-section]');
    const refreshUrl = section?.dataset?.timelineRefreshUrl?.trim() ?? '';

    if (! refreshUrl || ! section) {
        document.dispatchEvent(new CustomEvent('customer360:email-sent'));

        return;
    }

    try {
        const response = await fetch(`${refreshUrl}?offset=0`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            return;
        }

        const payload = await response.json();

        if (typeof payload.html === 'string' && payload.html !== '') {
            section.outerHTML = payload.html;
            const { initUnifiedTimeline } = await import('./unified-timeline');
            initUnifiedTimeline(document.querySelector('[data-customer-360-content-host]') ?? document);
            document.dispatchEvent(new CustomEvent('customer360:timeline-refreshed'));
        }
    } catch {
        // Best-effort.
    }
};

const stopPolling = () => {
    if (state.pollTimer) {
        clearInterval(state.pollTimer);
        state.pollTimer = null;
    }
};

const pollForNewer = async () => {
    const elements = getElements();

    if (! elements || ! state.threadUrl || state.sending) {
        return;
    }

    const cursor = newestCursorFromMessages(state.messages);

    if (! cursor) {
        return;
    }

    try {
        const response = await fetch(buildThreadQuery(state.threadUrl, { ...cursor, limit: 50 }), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            return;
        }

        const payload = await response.json();
        const newer = payload.messages || [];

        if (newer.length === 0) {
            return;
        }

        const stickToBottom = isNearBottom(elements.thread);
        state.messages = [...state.messages, ...newer];
        renderThreadList(elements, newer, { prepend: false });
        updateHeaderStats(elements, payload);
        applyReplyState(elements, payload);

        if (stickToBottom) {
            elements.thread.scrollTop = elements.thread.scrollHeight;
        }

        await refreshCustomer360TimelineAfterEmail();
        await markThreadRead(payload);
    } catch {
        // Ignore transient poll failures.
    }
};

const startPolling = () => {
    stopPolling();
    state.pollTimer = window.setInterval(pollForNewer, POLL_MS);
};

const loadOlderMessages = async (elements) => {
    if (! state.threadUrl || ! state.hasMoreOlder || state.loadingOlder) {
        return;
    }

    const cursor = oldestCursorFromMessages(state.messages);

    if (! cursor) {
        return;
    }

    state.loadingOlder = true;
    const previousHeight = elements.thread.scrollHeight;
    const previousTop = elements.thread.scrollTop;

    try {
        const response = await fetch(buildThreadQuery(state.threadUrl, { ...cursor, limit: 50 }), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const older = payload.messages || [];
        state.hasMoreOlder = Boolean(payload.has_more_older);
        state.messages = [...older, ...state.messages];
        renderThreadList(elements, older, { prepend: true });
        updateLoadOlder(elements);
        elements.thread.scrollTop = elements.thread.scrollHeight - previousHeight + previousTop;
    } catch {
            notifyChannelFailure('Unable to load older messages.');
    } finally {
        state.loadingOlder = false;
    }
};

export const openServiceCaseEmailWorkspace = async ({
    threadUrl,
    readUrl = null,
    focusIncomingId = null,
} = {}) => {
    const elements = getElements();

    if (! elements || ! globalThis.bootstrap?.Modal || ! threadUrl) {
        return;
    }

    const modalInstance = globalThis.bootstrap.Modal.getOrCreateInstance(elements.modal);
    state.threadUrl = threadUrl;
    state.readUrl = readUrl;
    state.focusIncomingId = focusIncomingId;
    state.messages = [];
    state.hasMoreOlder = false;
    state.subjectTouched = false;
    stopPolling();

    elements.error.hidden = true;
    elements.error.textContent = '';
    elements.loading.hidden = false;
    elements.thread.hidden = true;
    elements.empty.hidden = true;
    updateHeaderStats(elements, {});
    resetComposer(elements);
    if (elements.subtitle) {
        elements.subtitle.textContent = 'Service Case conversation';
    }

    modalInstance.show();

    try {
        const response = await fetch(buildThreadQuery(threadUrl, { limit: 50 }), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        elements.loading.hidden = true;
        state.messages = payload.messages || [];
        state.hasMoreOlder = Boolean(payload.has_more_older);

        if (state.messages.length === 0) {
            elements.thread.hidden = true;
            elements.empty.hidden = false;
        } else {
            elements.empty.hidden = true;
            elements.thread.hidden = false;
            renderThreadList(elements, state.messages);
            updateLoadOlder(elements);
            elements.thread.scrollTop = elements.thread.scrollHeight;
        }

        updateHeaderStats(elements, payload);
        applyReplyState(elements, payload);
        await markThreadRead(payload);

        if (focusIncomingId) {
            highlightMessage(elements, focusIncomingId);
        }

        startPolling();
    } catch {
        elements.loading.hidden = true;
        elements.error.textContent = 'Unable to load the email conversation. Please try again.';
        elements.error.hidden = false;
    }
};

const sendReply = async (elements) => {
    const messageId = elements.modal.dataset.replyToId;

    if (! messageId || ! canStartSend({ sending: state.sending, body: elements.body.value })) {
        if (! state.sending && String(elements.body.value || '').trim() === '') {
            elements.sendError.textContent = 'Write a message before sending.';
            elements.sendError.hidden = false;
            elements.body.focus();
        }

        return;
    }

    state.sending = true;
    elements.sendError.hidden = true;
    elements.send.disabled = true;

    const draftSubject = elements.subject.value;
    const draftBody = elements.body.value;

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
                subject: draftSubject,
                body_html: textToHtml(draftBody),
                template_key: 'blank',
            }),
        });

        const payload = await response.json().catch(() => ({}));

        if (! response.ok) {
            elements.subject.value = draftSubject;
            elements.body.value = draftBody;
            elements.sendError.textContent = payload.error || payload.message || 'Failed to send reply.';
            elements.sendError.hidden = false;
            notifyChannelFailure('Email reply failed.');
            elements.body.focus();

            return;
        }

        const outgoing = payload.outgoing_email_message || {};
        const bubble = {
            id: outgoing.id,
            direction: 'outbound',
            subject: draftSubject,
            preview: draftBody.slice(0, 280),
            to_email: outgoing.to_email,
            status: outgoing.status || 'sent',
            status_label: outgoing.status || 'Sent',
            occurred_at: outgoing.sent_at || new Date().toISOString(),
            can_open: false,
        };

        state.messages = [...state.messages, bubble];
        renderThreadList(elements, [bubble]);
        elements.thread.scrollTop = elements.thread.scrollHeight;
        elements.body.value = '';
        state.subjectTouched = false;
        setComposerOpen(elements, false);
        notifyChannelSuccess('Reply sent.');
        await refreshCustomer360TimelineAfterEmail();
        const lastBubble = elements.threadList.lastElementChild;
        highlightChannelTarget(lastBubble);
    } catch {
        elements.subject.value = draftSubject;
        elements.body.value = draftBody;
        elements.sendError.textContent = 'Failed to send reply. Please try again.';
        elements.sendError.hidden = false;
        notifyChannelFailure('Email reply failed.');
        elements.body.focus();
    } finally {
        state.sending = false;
        elements.send.disabled = false;
    }
};

const resolveThreadUrlsFromDrawer = () => {
    const button = document.querySelector('[data-c360-email-open]');

    return {
        threadUrl: button?.dataset?.c360EmailThreadUrl || state.threadUrl,
        readUrl: button?.dataset?.c360EmailReadUrl || state.readUrl,
    };
};

export const initServiceCaseEmailWorkspace = () => {
    if (document.body.dataset.serviceCaseEmailWorkspaceBound === 'true') {
        return;
    }

    document.body.dataset.serviceCaseEmailWorkspaceBound = 'true';

    document.body.addEventListener('click', (event) => {
        const openButton = event.target.closest('[data-c360-email-open]');

        if (openButton instanceof HTMLElement) {
            event.preventDefault();
            openServiceCaseEmailWorkspace({
                threadUrl: openButton.dataset.c360EmailThreadUrl,
                readUrl: openButton.dataset.c360EmailReadUrl,
            });

            return;
        }

        const jumpButton = event.target.closest('[data-c360-email-jump]');

        if (jumpButton instanceof HTMLElement) {
            event.preventDefault();
            const urls = resolveThreadUrlsFromDrawer();
            openServiceCaseEmailWorkspace({
                threadUrl: urls.threadUrl,
                readUrl: urls.readUrl,
                focusIncomingId: jumpButton.dataset.c360EmailJump,
            });

            return;
        }

        const readFull = event.target.closest('[data-sc-email-open-full]');

        if (readFull instanceof HTMLElement) {
            event.preventDefault();
            const messageId = readFull.dataset.scEmailOpenFull;

            if (messageId) {
                openIncomingEmailModal(messageId);
            }

            return;
        }

        const elements = getElements();

        if (! elements) {
            return;
        }

        if (event.target.closest('[data-sc-email-load-older]')) {
            loadOlderMessages(elements);

            return;
        }

        if (event.target.closest('[data-sc-email-reply-toggle]')) {
            setComposerOpen(elements, true);
            elements.subject.value = preferDefaultSubject(
                elements.subject.value,
                state.defaultSubject,
                state.subjectTouched,
            );
            elements.body.focus();

            return;
        }

        if (event.target.closest('[data-sc-email-reply-cancel]')) {
            setComposerOpen(elements, false);

            return;
        }

        if (event.target.closest('[data-sc-email-send]')) {
            sendReply(elements);
        }
    });

    document.body.addEventListener('input', (event) => {
        const target = event.target;

        if (! (target instanceof HTMLElement)) {
            return;
        }

        if (target.matches('[data-sc-email-subject]')) {
            state.subjectTouched = true;
        }
    });

    document.body.addEventListener('hidden.bs.modal', (event) => {
        if (event.target?.matches?.('[data-service-case-email-modal]')) {
            stopPolling();
        }
    });
};
