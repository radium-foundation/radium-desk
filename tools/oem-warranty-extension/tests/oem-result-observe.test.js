import { describe, expect, it, vi } from 'vitest';
import { observeOemResult } from '../src/lib/oem-result-observe.js';

describe('observeOemResult', () => {
    it('emits once when readiness flips after delayed DOM update', async () => {
        document.body.innerHTML = '<div id="host"></div>';
        const onEmit = vi.fn();
        let ready = false;

        observeOemResult(document, {
            isReady: () => ready,
            buildPayload: () => ({ outcome: 'found', rows: [] }),
            onEmit,
            maxObserveMs: 500,
        });

        expect(onEmit).not.toHaveBeenCalled();
        ready = true;
        const host = document.getElementById('host');
        host?.appendChild(document.createElement('span'));

        await vi.waitFor(() => {
            expect(onEmit).toHaveBeenCalledOnce();
        });
    });

    it('does not emit twice on repeated mutations', () => {
        document.body.innerHTML = '<motion-root></motion-root>';

        const onEmit = vi.fn();
        observeOemResult(document, {
            isReady: () => true,
            buildPayload: () => ({ outcome: 'found' }),
            onEmit,
            maxObserveMs: 500,
        });

        document.body.appendChild(document.createElement('p'));
        document.body.appendChild(document.createElement('p'));
        expect(onEmit).toHaveBeenCalledOnce();
    });

    it('stops observing after timeout without emit', () => {
        vi.useFakeTimers();
        const onEmit = vi.fn();

        observeOemResult(document, {
            isReady: () => false,
            buildPayload: () => ({ outcome: 'found' }),
            onEmit,
            maxObserveMs: 100,
        });

        vi.advanceTimersByTime(150);
        document.body.innerHTML = '<div id="late"></div>';
        expect(onEmit).not.toHaveBeenCalled();
        vi.useRealTimers();
    });
});