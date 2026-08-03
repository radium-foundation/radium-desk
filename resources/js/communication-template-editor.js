const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const initPreview = (form) => {
    const subjectEl = form.querySelector('#subject');
    const bodyEl = form.querySelector('#body_html');
    const greetingEl = form.querySelector('#greeting_style');
    const signatureEl = form.querySelector('#signature_mode');
    const previewSubject = form.querySelector('[data-preview-subject]');
    const previewHtml = form.querySelector('[data-preview-html]');
    const previewFrame = form.querySelector('[data-preview-frame]');
    const previewUrl = form.dataset.previewUrl;

    const renderLocal = () => {
        if (! previewHtml) {
            return;
        }

        const greeting = greetingEl?.selectedOptions?.[0]?.textContent ?? '';
        const body = bodyEl?.value ?? '';
        previewSubject.textContent = subjectEl?.value || '(no subject)';
        previewHtml.innerHTML = `<p>${greeting.replace(/</g, '&lt;')}</p>${body}`;
    };

    const renderRemote = async () => {
        if (! previewUrl) {
            renderLocal();
            return;
        }

        try {
            const response = await fetch(previewUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    subject: subjectEl?.value ?? '',
                    body_html: bodyEl?.value ?? '',
                    greeting_style: greetingEl?.value ?? '',
                    signature_mode: signatureEl?.value ?? '',
                }),
            });

            if (! response.ok) {
                renderLocal();
                return;
            }

            const payload = await response.json();
            previewSubject.textContent = payload.subject || '(no subject)';
            previewHtml.innerHTML = payload.html || '';
        } catch (error) {
            renderLocal();
        }
    };

    ['input', 'change'].forEach((eventName) => {
        form.addEventListener(eventName, () => {
            window.clearTimeout(form._previewTimer);
            form._previewTimer = window.setTimeout(renderRemote, 250);
        });
    });

    form.querySelectorAll('[data-preview-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            form.querySelectorAll('[data-preview-mode]').forEach((el) => el.classList.remove('active'));
            button.classList.add('active');
            if (previewFrame) {
                previewFrame.style.maxWidth = button.dataset.previewMode === 'mobile' ? '375px' : '100%';
            }
        });
    });

    form.querySelectorAll('[data-insert-variable]').forEach((button) => {
        button.addEventListener('click', () => {
            if (! bodyEl) {
                return;
            }
            const token = `{{${button.dataset.insertVariable}}}`;
            const start = bodyEl.selectionStart ?? bodyEl.value.length;
            const end = bodyEl.selectionEnd ?? start;
            bodyEl.value = `${bodyEl.value.slice(0, start)}${token}${bodyEl.value.slice(end)}`;
            bodyEl.focus();
            bodyEl.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    renderRemote();
};

document.querySelectorAll('[data-template-editor]').forEach((form) => initPreview(form));
