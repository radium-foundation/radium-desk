/**
 * Workforce Management · Attendance matrix (Phase 1)
 * Client-side search + clickable cell hooks for a future attendance drawer.
 */

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

function initAttendanceCellHooks(root) {
    root.querySelectorAll('[data-attendance-drawer-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            let payload = null;

            try {
                payload = JSON.parse(button.getAttribute('data-drawer-payload') || 'null');
            } catch (error) {
                payload = null;
            }

            // Phase 1: prepare the hook only — drawer UI comes later.
            root.dispatchEvent(
                new CustomEvent('workforce-attendance:cell-open', {
                    bubbles: true,
                    detail: {
                        userId: Number(button.getAttribute('data-user-id') || 0),
                        workDate: button.getAttribute('data-work-date'),
                        kind: button.getAttribute('data-kind'),
                        payload,
                    },
                }),
            );
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-workforce-management-attendance]');

    if (!root) {
        return;
    }

    initAttendanceSearch(root);
    initAttendanceCellHooks(root);
});
