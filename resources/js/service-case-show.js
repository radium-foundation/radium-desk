import { isTypingTarget } from './keyboard/guards';

export const initServiceCaseShow = () => {
    const root = document.querySelector('[data-service-case-show]');

    if (!root) {
        return;
    }

    const quickActions = root.querySelector('[data-quick-actions]');
    const stickyBar = root.querySelector('[data-sticky-bar]');

    if (quickActions && stickyBar) {
        const observer = new IntersectionObserver(
            ([entry]) => {
                stickyBar.classList.toggle('d-none', entry.isIntersecting);
                stickyBar.classList.toggle('service-case-sticky-bar--visible', ! entry.isIntersecting);
            },
            { threshold: 0, rootMargin: '0px 0px -1px 0px' },
        );

        observer.observe(quickActions);
    }

    const openModal = (selector) => {
        const modalElement = document.querySelector(selector);

        if (!modalElement || !window.bootstrap?.Modal) {
            return;
        }

        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    };

    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (isTypingTarget(event.target)) {
            return;
        }

        const key = event.key.toLowerCase();

        if (key === 'n' || key === 'r') {
            const noteTrigger = root.querySelector('[data-workspace-trigger="remark"]');

            if (noteTrigger instanceof HTMLButtonElement) {
                event.preventDefault();
                noteTrigger.click();
                return;
            }

            if (key === 'r' && root.querySelector('[data-sc-action="remark"]')) {
                event.preventDefault();
                openModal('#remarkModal');
            }
        }

        if (key === 'a' && root.querySelector('[data-sc-action="assign"]')) {
            event.preventDefault();
            openModal('#assignModal');
            return;
        }

        if (key === 'e') {
            const editLink = root.querySelector('[data-sc-action="edit"]');

            if (editLink instanceof HTMLAnchorElement) {
                event.preventDefault();
                editLink.click();
            }
        }

        if (key === '/') {
            const searchInput = document.getElementById('global-search-input');

            if (searchInput instanceof HTMLInputElement) {
                event.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    if (document.querySelector('#assignModal') && document.querySelector('.is-invalid#modal_assigned_to_user_id, .is-invalid[name="assigned_to_user_id"]')) {
        openModal('#assignModal');
    }

    if (document.querySelector('#remarkModal') && document.querySelector('.is-invalid#modal_note_body, .is-invalid[name="body"]')) {
        openModal('#remarkModal');
    }

    if (document.querySelector('#resolveModal') && document.querySelector('.is-invalid#resolve_remark_body')) {
        openModal('#resolveModal');
    }

    if (document.querySelector('#closeModal') && document.querySelector('.is-invalid#close_remark_body')) {
        openModal('#closeModal');
    }
};
