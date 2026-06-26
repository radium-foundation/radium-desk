import { beforeEach, describe, expect, it } from 'vitest';

const initMentionTextareas = (root = document) => {
    root.querySelectorAll('[data-mention-textarea]').forEach((textarea) => {
        if (textarea.dataset.mentionBound === 'true') {
            return;
        }

        textarea.dataset.mentionBound = 'true';

        const listId = textarea.dataset.mentionList;
        const datalist = listId ? document.getElementById(listId) : null;

        if (!datalist) {
            return;
        }

        const users = Array.from(datalist.options).map((option) => option.value);

        const dropdown = document.createElement('div');
        dropdown.className = 'mention-suggestions dropdown-menu';
        dropdown.setAttribute('role', 'listbox');
        document.body.appendChild(dropdown);

        let activeIndex = -1;
        let mentionStart = -1;

        const hideDropdown = () => {
            dropdown.classList.remove('show');
            dropdown.style.display = 'none';
            activeIndex = -1;
            mentionStart = -1;
        };

        const getMentionMatch = () => {
            const cursorPos = textarea.selectionStart ?? textarea.value.length;
            const before = textarea.value.slice(0, cursorPos);
            const match = before.match(/@([\p{L}\p{M}'.]*)$/u);

            if (!match) {
                return null;
            }

            return {
                term: match[1],
                start: before.length - match[0].length,
            };
        };

        const filterUsers = (term) => {
            const lower = term.toLowerCase();

            if (lower === '') {
                return users;
            }

            return users.filter((name) => name.toLowerCase().startsWith(lower));
        };

        const positionDropdown = () => {
            const rect = textarea.getBoundingClientRect();
            dropdown.style.top = `${rect.bottom}px`;
            dropdown.style.left = `${rect.left}px`;
            dropdown.style.minWidth = `${rect.width}px`;
        };

        const setActiveItem = (index) => {
            const items = dropdown.querySelectorAll('.dropdown-item');

            items.forEach((item, itemIndex) => {
                item.classList.toggle('active', itemIndex === index);
            });
            activeIndex = index;
        };

        const applyMention = (name) => {
            if (mentionStart < 0) {
                return;
            }

            const cursorPos = textarea.selectionStart ?? textarea.value.length;
            const before = textarea.value.slice(0, mentionStart);
            const after = textarea.value.slice(cursorPos);
            textarea.value = `${before}@${name} ${after}`;
            const newPos = before.length + name.length + 2;
            textarea.setSelectionRange(newPos, newPos);
            hideDropdown();
            textarea.focus();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        };

        const showDropdown = (matches) => {
            if (matches.length === 0) {
                hideDropdown();

                return;
            }

            dropdown.innerHTML = matches.map((name) => (
                `<button type="button" class="dropdown-item" role="option" data-mention-name="${name}">${name}</button>`
            )).join('');

            positionDropdown();
            dropdown.classList.add('show');
            dropdown.style.display = 'block';
            setActiveItem(0);
        };

        const refreshDropdown = () => {
            const mentionMatch = getMentionMatch();

            if (!mentionMatch) {
                hideDropdown();

                return;
            }

            mentionStart = mentionMatch.start;
            showDropdown(filterUsers(mentionMatch.term));
        };

        textarea.addEventListener('input', refreshDropdown);
        textarea.addEventListener('click', refreshDropdown);
        textarea.addEventListener('keyup', refreshDropdown);

        textarea.addEventListener('keydown', (event) => {
            if (!dropdown.classList.contains('show')) {
                return;
            }

            const items = dropdown.querySelectorAll('.dropdown-item');

            if (items.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActiveItem((activeIndex + 1) % items.length);

                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActiveItem((activeIndex - 1 + items.length) % items.length);

                return;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                if (activeIndex >= 0 && items[activeIndex]) {
                    event.preventDefault();
                    applyMention(items[activeIndex].dataset.mentionName);
                }
            }
        });

        dropdown.addEventListener('mousedown', (event) => {
            const item = event.target.closest('[data-mention-name]');

            if (!item) {
                return;
            }

            event.preventDefault();
            applyMention(item.dataset.mentionName);
        });
    });
};

describe('mention textarea autocomplete', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <textarea id="remark" data-mention-textarea data-mention-list="mention-users"></textarea>
            <datalist id="mention-users">
                <option value="Damini Sharma"></option>
                <option value="Ravi Kumar"></option>
            </datalist>
        `;
        initMentionTextareas();
    });

    it('shows all users immediately after typing @', () => {
        const textarea = document.getElementById('remark');
        textarea.value = '@';
        textarea.setSelectionRange(1, 1);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        const dropdown = document.querySelector('.mention-suggestions');

        expect(dropdown?.classList.contains('show')).toBe(true);
        expect(dropdown?.querySelectorAll('.dropdown-item').length).toBe(2);
    });

    it('filters suggestions while typing after @', () => {
        const textarea = document.getElementById('remark');
        textarea.value = '@Dam';
        textarea.setSelectionRange(4, 4);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        const dropdown = document.querySelector('.mention-suggestions');
        const items = dropdown?.querySelectorAll('.dropdown-item') ?? [];

        expect(items.length).toBe(1);
        expect(items[0]?.textContent).toBe('Damini Sharma');
    });
});
