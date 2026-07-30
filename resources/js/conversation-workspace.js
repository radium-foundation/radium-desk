const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const fieldPayloadKey = (key) => {
    if (key === 'order_id') {
        return 'order_id_hint';
    }

    return key;
};

const renderQuestion = (root, question, captured = {}) => {
    const guide = root.querySelector('[data-cw-guide]');

    if (!guide) {
        return;
    }

    if (!question) {
        guide.innerHTML = `
            <div class="cw-question cw-question--done" data-cw-question-done>
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <span>Ready. Keep talking — capture more anytime below.</span>
            </div>
        `;

        return;
    }

    const value = captured[question.key] ?? captured[fieldPayloadKey(question.key)] ?? '';
    let inputHtml = '';

    if (question.input_type === 'textarea') {
        inputHtml = `<textarea class="form-control form-control-sm cw-input" data-cw-field="${question.key}" rows="2" placeholder="${question.prompt}">${value ?? ''}</textarea>`;
    } else if (question.input_type === 'select') {
        const options = (question.options ?? [])
            .map((option) => `<option value="${option.value}" ${value === option.value ? 'selected' : ''}>${option.label}</option>`)
            .join('');
        inputHtml = `<select class="form-select form-select-sm cw-input" data-cw-field="${question.key}"><option value="">Select…</option>${options}</select>`;
    } else if (question.input_type === 'choice') {
        const buttons = (question.options ?? [])
            .map((option) => `<button type="button" class="cw-choice-btn" data-cw-choice="${option.value}">${option.label}</button>`)
            .join('');
        inputHtml = `<div class="cw-choice-row" data-cw-field="${question.key}">${buttons}</div>`;
    } else {
        const type = question.input_type === 'email' ? 'email' : 'text';
        inputHtml = `<input type="${type}" class="form-control form-control-sm cw-input" data-cw-field="${question.key}" value="${value ?? ''}" placeholder="${question.prompt}" autocomplete="off" />`;
    }

    guide.innerHTML = `
        <div class="cw-question" data-cw-question>
            <div class="cw-question-prompt">
                <strong data-cw-prompt>${question.prompt}</strong>
                ${question.required ? '<span class="cw-required" aria-label="Required">*</span>' : ''}
            </div>
            ${question.hint ? `<p class="cw-question-hint">${question.hint}</p>` : ''}
            <div class="cw-question-input" data-cw-input-host>${inputHtml}</div>
            <div class="cw-question-actions">
                <button type="button" class="btn btn-sm btn-primary cw-save-btn" data-cw-save>Continue</button>
                ${question.skippable ? '<button type="button" class="btn btn-sm btn-link cw-skip-btn" data-cw-skip>Skip</button>' : ''}
            </div>
        </div>
    `;

    guide.querySelector('[data-cw-field]')?.focus?.();
};

const updateChecklist = (root, checklist, progress) => {
    const progressEl = root.querySelector('[data-cw-progress]');

    if (progressEl && progress?.label) {
        progressEl.textContent = progress.label;
    }

    const list = root.querySelector('.cw-checklist-list');

    if (!list || !checklist) {
        return;
    }

    list.innerHTML = Object.entries(checklist).map(([key, done]) => `
        <li class="${done ? 'is-done' : ''}">
            <i class="bi ${done ? 'bi-check-circle-fill' : 'bi-circle'}" aria-hidden="true"></i>
            <span>${key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase())}</span>
        </li>
    `).join('');
};

const startTimer = (root) => {
    const timer = root.querySelector('[data-cw-timer]');

    if (!timer) {
        return () => {};
    }

    const startedAt = Date.parse(timer.getAttribute('data-cw-timer-started-at') ?? '') || Date.now();

    const tick = () => {
        const elapsed = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
        const minutes = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const seconds = String(elapsed % 60).padStart(2, '0');
        timer.textContent = `${minutes}:${seconds}`;
    };

    tick();
    const id = window.setInterval(tick, 1000);

    return () => window.clearInterval(id);
};

export const initConversationWorkspace = (contentHost, { showToast } = {}) => {
    const root = contentHost.querySelector('[data-conversation-workspace]');

    if (!root || root.dataset.cwBound === '1') {
        return null;
    }

    root.dataset.cwBound = '1';

    const updateUrl = root.getAttribute('data-cw-update-url');
    const callId = root.getAttribute('data-cw-call-id') || '';
    let saving = false;
    const stopTimer = startTimer(root);

    const parseSession = () => {
        try {
            return JSON.parse(root.getAttribute('data-cw-session') || '{}');
        } catch {
            return {};
        }
    };

    const applyWorkspace = (workspace) => {
        if (!workspace) {
            return;
        }

        root.setAttribute('data-cw-session', JSON.stringify(workspace));
        renderQuestion(root, workspace.active_question ?? null, workspace.captured ?? {});
        updateChecklist(root, workspace.checklist, workspace.progress);
    };

    const patch = async (payload) => {
        if (!updateUrl || saving) {
            return null;
        }

        saving = true;

        try {
            const response = await fetch(updateUrl, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    call_id: callId || undefined,
                    ...payload,
                }),
            });

            if (!response.ok) {
                showToast?.('Could not save. Try again.');

                return null;
            }

            const data = await response.json();

            if (data.workspace) {
                applyWorkspace(data.workspace);
            }

            return data;
        } catch {
            showToast?.('Could not save. Try again.');

            return null;
        } finally {
            saving = false;
        }
    };

    const currentFieldValue = () => {
        const choiceHost = root.querySelector('[data-cw-field].cw-choice-row');

        if (choiceHost) {
            return null;
        }

        const field = root.querySelector('[data-cw-field]');

        if (!field) {
            return null;
        }

        return {
            key: field.getAttribute('data-cw-field'),
            value: field.value?.trim?.() ?? '',
        };
    };

    root.addEventListener('click', async (event) => {
        const choice = event.target.closest('[data-cw-choice]');

        if (choice) {
            const field = choice.closest('[data-cw-field]')?.getAttribute('data-cw-field');

            if (field === 'whatsapp') {
                await patch({ whatsapp_choice: choice.getAttribute('data-cw-choice') });
            }

            return;
        }

        if (event.target.closest('[data-cw-save]')) {
            const current = currentFieldValue();

            if (!current) {
                return;
            }

            if (current.value === '') {
                showToast?.('Enter a value or skip.');

                return;
            }

            await patch({
                [fieldPayloadKey(current.key)]: current.value,
                completed_fields: [current.key],
                current_step: current.key,
            });

            return;
        }

        if (event.target.closest('[data-cw-skip]')) {
            const field = root.querySelector('[data-cw-field]')?.getAttribute('data-cw-field');

            if (!field) {
                return;
            }

            await patch({
                skip_field: field,
                current_step: field,
            });

            return;
        }

        if (event.target.closest('[data-cw-save-more]')) {
            const payload = {};

            root.querySelectorAll('[data-cw-more]').forEach((input) => {
                payload[input.getAttribute('data-cw-more')] = input.value.trim();
            });

            await patch(payload);
            showToast?.('Details saved.');
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.target.tagName === 'TEXTAREA') {
            return;
        }

        if (!event.target.matches('[data-cw-field]')) {
            return;
        }

        event.preventDefault();
        root.querySelector('[data-cw-save]')?.click();
    });

    return {
        destroy() {
            stopTimer();
            delete root.dataset.cwBound;
        },
        session: parseSession,
    };
};
