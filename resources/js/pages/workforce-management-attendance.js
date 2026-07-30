/**
 * Reusable Workforce Member 360 drawer.
 * Can be opened from Attendance and future Workforce modules.
 */

const openClass = 'is-open';
const bodyOpenClass = 'wm360-drawer-open';

function endpointFor(template, userId, month, day) {
    const base = String(template || '').replace('__USER__', String(userId));
    const url = new URL(base, window.location.origin);

    if (month) {
        url.searchParams.set('month', month);
    }

    if (day) {
        url.searchParams.set('day', day);
    }

    return `${url.pathname}${url.search}`;
}

function createWorkforceMember360Drawer(root = document) {
    const drawer = root.querySelector('[data-wm360-drawer]') || document.querySelector('[data-wm360-drawer]');

    if (!drawer) {
        return null;
    }

    const backdrop = drawer.querySelector('[data-wm360-backdrop]');
    const closeButton = drawer.querySelector('[data-wm360-close]');
    const loading = drawer.querySelector('[data-wm360-loading]');
    const error = drawer.querySelector('[data-wm360-error]');
    const contentHost = drawer.querySelector('[data-wm360-content-host]');
    const endpointTemplate = drawer.getAttribute('data-wm360-endpoint-template');
    let abortController = null;
    let lastFocused = null;

    const setLoading = (isLoading) => {
        if (loading) {
            loading.hidden = !isLoading;
        }
    };

    const setError = (message) => {
        if (!error) {
            return;
        }

        if (!message) {
            error.classList.add('d-none');
            error.textContent = '';
            return;
        }

        error.textContent = message;
        error.classList.remove('d-none');
    };

    const focusFocusedDay = () => {
        const focused = contentHost?.querySelector('#wm360-focused-day, .wm360-timeline__row.is-focused');

        if (focused) {
            focused.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    };

    const bindContentActions = () => {
        contentHost?.querySelectorAll('[data-wm360-scroll]').forEach((link) => {
            link.addEventListener('click', (event) => {
                const href = link.getAttribute('href') || '';

                if (!href.startsWith('#')) {
                    return;
                }

                const target = contentHost.querySelector(href);

                if (!target) {
                    return;
                }

                event.preventDefault();
                target.scrollIntoView({ block: 'start', behavior: 'smooth' });
            });
        });
    };

    const close = () => {
        if (abortController) {
            abortController.abort();
            abortController = null;
        }

        drawer.classList.remove(openClass);
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove(bodyOpenClass);

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    };

    const open = async ({ userId, month = null, day = null } = {}) => {
        if (!userId) {
            return;
        }

        lastFocused = document.activeElement;
        drawer.classList.add(openClass);
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add(bodyOpenClass);
        setError('');
        setLoading(true);

        if (contentHost) {
            contentHost.innerHTML = '';
        }

        if (abortController) {
            abortController.abort();
        }

        abortController = new AbortController();

        try {
            const response = await fetch(endpointFor(endpointTemplate, userId, month, day), {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: abortController.signal,
            });

            if (!response.ok) {
                throw new Error(`Unable to load member profile (${response.status})`);
            }

            const html = await response.text();

            if (contentHost) {
                contentHost.innerHTML = html;
            }

            setLoading(false);
            bindContentActions();
            focusFocusedDay();
            closeButton?.focus();
        } catch (err) {
            if (err?.name === 'AbortError') {
                return;
            }

            setLoading(false);
            setError(err?.message || 'Unable to load Workforce Member 360.');
        }
    };

    backdrop?.addEventListener('click', close);
    closeButton?.addEventListener('click', close);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawer.classList.contains(openClass)) {
            close();
        }
    });

    return { open, close, drawer };
}

function currentAttendanceMonth(root) {
    const input = root.querySelector('#attendance-month');

    return input?.value || null;
}

function initAttendanceSearch(root) {
    const input = root.querySelector('[data-attendance-search]');
    const rows = root.querySelectorAll('[data-attendance-row]');

    if (!input || rows.length === 0) {
        return;
    }

    const applyFilter = () => {
        const query = input.value.trim().toLowerCase();

        rows.forEach((row) => {
            const name = row.getAttribute('data-employee-name') || '';
            const visible = query === '' || name.includes(query);
            row.hidden = !visible;
        });
    };

    input.addEventListener('input', applyFilter);
}

function initAttendanceEntryPoints(root, member360) {
    root.querySelectorAll('[data-attendance-drawer-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            let payload = null;

            try {
                payload = JSON.parse(button.getAttribute('data-drawer-payload') || 'null');
            } catch (error) {
                payload = null;
            }

            const detail = {
                userId: Number(button.getAttribute('data-user-id') || 0),
                workDate: button.getAttribute('data-work-date'),
                kind: button.getAttribute('data-kind'),
                payload,
            };

            root.dispatchEvent(
                new CustomEvent('workforce-attendance:cell-open', {
                    bubbles: true,
                    detail,
                }),
            );

            member360?.open({
                userId: detail.userId,
                month: currentAttendanceMonth(root),
                day: detail.workDate,
            });
        });
    });

    root.querySelectorAll('[data-wm360-open-member]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const userId = Number(trigger.getAttribute('data-user-id') || 0);

            root.dispatchEvent(
                new CustomEvent('workforce-member-360:open', {
                    bubbles: true,
                    detail: { userId, month: currentAttendanceMonth(root), day: null },
                }),
            );

            member360?.open({
                userId,
                month: currentAttendanceMonth(root),
                day: null,
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const member360 = createWorkforceMember360Drawer(document);
    const root = document.querySelector('[data-workforce-management-attendance]');

    if (!root) {
        return;
    }

    initAttendanceSearch(root);
    initAttendanceEntryPoints(root, member360);
});

export { createWorkforceMember360Drawer };
