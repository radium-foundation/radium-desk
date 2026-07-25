import { beforeEach, describe, expect, it, vi } from 'vitest';
import { appendSupportReference, initiateBonvoiceClickToCall } from '../../resources/js/bonvoice-click-to-call';
import { resetOutboundClickToCallTracking } from '../../resources/js/bonvoice-outbound-call-status';

const makeButton = () => {
    document.body.innerHTML = `
        <button
            type="button"
            data-bonvoice-click-to-call
            data-bonvoice-click-to-call-url="/bonvoice/click-to-call"
            data-bonvoice-order-id="12"
            data-tel-fallback="tel:9876543210"
        >
            <span data-bonvoice-call-status-label>Call</span>
        </button>
    `;

    return document.querySelector('[data-bonvoice-click-to-call]');
};

describe('bonvoice click-to-call support reference', () => {
    it('appends reference id to the existing failure message', () => {
        expect(appendSupportReference('Automatic calling failed.', 'BV-81AF93D2')).toBe(
            'Automatic calling failed.\n\nRef: BV-81AF93D2',
        );
    });

    it('uses default failure message when message is empty', () => {
        expect(appendSupportReference(null, 'BV-81AF93D2')).toBe(
            'Automatic calling failed.\n\nRef: BV-81AF93D2',
        );
    });

    it('does not append provider details when reference id is missing', () => {
        expect(appendSupportReference('Automatic calling failed.', null)).toBe('Automatic calling failed.');
    });
});

describe('bonvoice click-to-call initiate lifecycle', () => {
    beforeEach(() => {
        resetOutboundClickToCallTracking();
        vi.unstubAllGlobals();
    });

    it('keeps the button disabled after a successful initiate', async () => {
        const button = makeButton();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                success: true,
                message: 'Calling your registered mobile...',
                event_id: 'evt-abc123',
            }),
        }));

        const result = await initiateBonvoiceClickToCall(button, { showToast: vi.fn() });

        expect(result.success).toBe(true);
        expect(button.disabled).toBe(true);
        expect(button.classList.contains('is-loading')).toBe(false);
        expect(button.getAttribute('aria-busy')).toBe('false');
        expect(button.querySelector('[data-bonvoice-call-status-label]')?.textContent).toBe('Calling...');
    });

    it('re-enables the button after a failed initiate', async () => {
        const button = makeButton();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({
                success: false,
                message: 'Automatic calling failed.',
                reference_id: 'BV-81AF93D2',
                retriable: true,
            }),
        }));

        const result = await initiateBonvoiceClickToCall(button, { showToast: vi.fn() });

        expect(result.success).toBe(false);
        expect(button.disabled).toBe(false);
        expect(button.classList.contains('is-loading')).toBe(false);
    });

    it('skips duplicate clicks while a call is already in progress', async () => {
        const button = makeButton();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                success: true,
                event_id: 'evt-dup-001',
            }),
        });

        vi.stubGlobal('fetch', fetchMock);

        await initiateBonvoiceClickToCall(button, { showToast: vi.fn() });
        const second = await initiateBonvoiceClickToCall(button, { showToast: vi.fn() });

        expect(second.skipped).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(button.disabled).toBe(true);
    });
});
