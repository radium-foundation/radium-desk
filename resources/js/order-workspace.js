import { guardServiceReferenceAssignment } from './customer-intake';

let legacyVerificationModal = null;

export const setOrderWorkspaceLegacyVerificationModal = (modal) => {
    legacyVerificationModal = modal;
};

export function initOrderWorkspace() {
    const root = document.querySelector('[data-order-workspace]');

    if (!root) {
        return;
    }

    const tabs = Array.from(root.querySelectorAll('[data-workspace-tab]'));
    const panes = Array.from(root.querySelectorAll('[data-workspace-tab-pane]'));
    const tabTriggers = Array.from(root.querySelectorAll('[data-workspace-tab-trigger]'));
    const stickyBar = root.querySelector('[data-workspace-sticky-bar]');
    const stickySentinel = root.querySelector('[data-workspace-sticky-sentinel]');

    if (tabs.length === 0 || panes.length === 0) {
        return;
    }

    const activateTab = (tabKey) => {
        tabs.forEach((tab) => {
            const isActive = tab.dataset.workspaceTab === tabKey;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panes.forEach((pane) => {
            const isActive = pane.dataset.workspaceTabPane === tabKey;
            pane.classList.toggle('d-none', !isActive);

            if (isActive) {
                pane.dataset.loaded = 'true';
            }
        });
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activateTab(tab.dataset.workspaceTab);
        });
    });

    tabTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const tabKey = trigger.dataset.workspaceTabTrigger;

            if (tabKey) {
                activateTab(tabKey);
            }
        });
    });

    const initialTab = tabs.find((tab) => tab.classList.contains('active'))?.dataset.workspaceTab ?? 'overview';
    activateTab(initialTab);

    if (stickyBar && stickySentinel && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver(
            ([entry]) => {
                const isPinned = !entry.isIntersecting;
                stickyBar.hidden = !isPinned;
                stickyBar.setAttribute('aria-hidden', isPinned ? 'false' : 'true');
                stickyBar.classList.toggle('is-visible', isPinned);
            },
            { root: null, threshold: 0, rootMargin: '-56px 0px 0px 0px' },
        );

        observer.observe(stickySentinel);
    }

    initOrderWorkspaceTransactionGuard();
}

const initOrderWorkspaceTransactionGuard = () => {
    const form = document.querySelector('[data-order-workspace-transaction-form="true"]');

    if (!form) {
        return;
    }

    form.addEventListener('submit', (event) => {
        const requiresLegacyVerification = form.dataset.requiresLegacyVerification === 'true';
        const legacyVerificationUrl = form.dataset.legacyVerificationUrl;

        if (!requiresLegacyVerification || !legacyVerificationUrl || !legacyVerificationModal) {
            return;
        }

        event.preventDefault();

        guardServiceReferenceAssignment({
            requiresLegacyVerification: true,
            legacyVerificationUrl,
            legacyVerificationModal,
            onProceed: () => {
                form.dataset.legacyVerificationComplete = 'true';
                form.submit();
            },
        });
    });
};
