import { afterEach, describe, expect, it, vi } from 'vitest';
import { initCopyableIdentifiers } from '../../resources/js/copyable-identifiers';

describe('initCopyableIdentifiers', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        delete window.RadiumDesk;
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('does not throw when RadiumDesk namespace is missing', () => {
        delete window.RadiumDesk;

        expect(() => initCopyableIdentifiers()).not.toThrow();
    });

    it('initializes RadiumDesk namespace and binds copy delegation once', () => {
        const showToast = vi.fn();

        initCopyableIdentifiers(showToast);
        initCopyableIdentifiers(showToast);

        expect(window.RadiumDesk).toBeTruthy();
        expect(window.RadiumDesk.copyableDelegationBound).toBe(true);
    });

    it('copies identifier values on click', async () => {
        vi.stubGlobal('navigator', {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });

        const showToast = vi.fn();

        document.body.innerHTML = `
            <button
                type="button"
                data-copyable-identifier="serial"
                data-copy-value="SN-12345"
                data-copy-toast="Serial copied"
            >Copy serial</button>
        `;

        initCopyableIdentifiers(showToast);

        document.querySelector('[data-copyable-identifier="serial"]').dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith('SN-12345');
            expect(showToast).toHaveBeenCalledWith('Serial copied');
        });
    });
});
