export const initSystemSettingsSearch = () => {
    const input = document.querySelector('[data-system-settings-search]');
    const sections = document.querySelectorAll('[data-system-settings-sections] [data-setting-searchable], [data-system-settings-section]');

    if (! input) {
        return;
    }

    let highlightTimeout;

    const clearHighlights = () => {
        document.querySelectorAll('.system-settings-search-highlight').forEach((el) => {
            el.classList.remove('system-settings-search-highlight');
        });
        sections.forEach((section) => {
            section.closest('.system-settings-section')?.classList.remove('system-settings-section--hidden');
        });
    };

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        clearTimeout(highlightTimeout);

        if (! query) {
            clearHighlights();

            return;
        }

        let firstMatch = null;

        document.querySelectorAll('[data-setting-searchable]').forEach((row) => {
            const text = row.dataset.settingSearchable ?? '';
            const matches = text.includes(query);
            const section = row.closest('.system-settings-section');

            row.classList.toggle('system-settings-row--hidden', ! matches);

            if (matches && ! firstMatch) {
                firstMatch = row;
            }

            if (section) {
                const visibleRows = section.querySelectorAll('[data-setting-searchable]:not(.system-settings-row--hidden)');
                section.classList.toggle('system-settings-section--hidden', visibleRows.length === 0 && ! (section.dataset.settingSearchable ?? '').includes(query));
            }
        });

        document.querySelectorAll('[data-system-settings-section]').forEach((section) => {
            const sectionText = (section.querySelector('.system-settings-section__title')?.textContent ?? '').toLowerCase();
            const hasVisible = section.querySelector('[data-setting-searchable]:not(.system-settings-row--hidden)');

            if (sectionText.includes(query) || hasVisible) {
                section.classList.remove('system-settings-section--hidden');
            }
        });

        if (firstMatch) {
            firstMatch.classList.add('system-settings-search-highlight');
            firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
            highlightTimeout = setTimeout(() => {
                firstMatch.classList.remove('system-settings-search-highlight');
            }, 2000);
        }
    });
};
