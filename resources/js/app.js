import './bootstrap';
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

const SIDEBAR_STORAGE_KEY = 'radium.sidebarExpanded';

const isSidebarExpanded = () => localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';

const applySidebarState = (expanded) => {
    document.documentElement.classList.toggle('sidebar-expanded', expanded);
};

document.addEventListener('DOMContentLoaded', () => {
    applySidebarState(isSidebarExpanded());

    const toggleButtons = document.querySelectorAll('[data-sidebar-toggle]');

    toggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const expanded = !document.documentElement.classList.contains('sidebar-expanded');
            applySidebarState(expanded);
            localStorage.setItem(SIDEBAR_STORAGE_KEY, expanded ? 'true' : 'false');
        });
    });

    document.querySelectorAll('[data-toast-show]').forEach((element) => {
        bootstrap.Toast.getOrCreateInstance(element, {
            autohide: true,
            delay: 4500,
        }).show();
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element);
    });

    const quickCreateModalElement = document.getElementById('quickCreateModal');

    if (quickCreateModalElement) {
        const quickCreateModal = bootstrap.Modal.getOrCreateInstance(quickCreateModalElement);

        if (quickCreateModalElement.dataset.showOnLoad === 'true') {
            quickCreateModal.show();
        }

        quickCreateModalElement.addEventListener('hidden.bs.modal', () => {
            quickCreateModalElement.querySelectorAll('.is-invalid').forEach((field) => {
                field.classList.remove('is-invalid');
            });
        });
    }
});
