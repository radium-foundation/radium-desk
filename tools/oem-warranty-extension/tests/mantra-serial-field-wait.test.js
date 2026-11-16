import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    fillMantraSerialOnce,
    waitForMantraSerialField,
} from '../src/lib/mantra-serial-field-wait.js';

describe('waitForMantraSerialField', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });
    it('resolves immediately when field exists', async () => {
        document.body.innerHTML = '<input id="txtSerialNo" />';
        const el = await waitForMantraSerialField(document, '#txtSerialNo', 1_000);
        expect(el?.id).toBe('txtSerialNo');
    });

    it('resolves when field appears after delay', async () => {
        document.body.innerHTML = '';
        const promise = waitForMantraSerialField(document, '#txtSerialNo', 1_000, {
            now: () => Date.now(),
        });

        setTimeout(() => {
            const input = document.createElement('input');
            input.id = 'txtSerialNo';
            document.body.appendChild(input);
        }, 30);

        const el = await promise;
        expect(el?.id).toBe('txtSerialNo');
    });

    it('rejects when field never appears within timeout', async () => {
        await expect(
            waitForMantraSerialField(document, '#txtSerialNo-missing', 20),
        ).rejects.toThrow('timeout');
    });
});

describe('fillMantraSerialOnce', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('fills serial exactly once and does not touch captcha', () => {
        document.body.innerHTML = `
            <input id="txtSerialNo" type="text" />
            <input id="txtCaptcha" type="text" />
            <button id="btnCheck">Check</button>
        `;

        let clicked = false;
        document.getElementById('btnCheck').addEventListener('click', () => {
            clicked = true;
        });

        const result = fillMantraSerialOnce(document, 'syn6419897');
        expect(result).toEqual({ ok: true });
        expect(document.getElementById('txtSerialNo').value).toBe('SYN6419897');
        expect(document.getElementById('txtCaptcha').value).toBe('');
        expect(clicked).toBe(false);
    });

    it('fails when verification does not match', () => {
        document.body.innerHTML = '<input id="txtSerialNo" type="text" />';
        const input = document.getElementById('txtSerialNo');
        const originalDescriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        Object.defineProperty(input, 'value', {
            configurable: true,
            get() {
                return '';
            },
            set() {},
        });

        const result = fillMantraSerialOnce(document, 'ABC123');
        expect(result.ok).toBe(false);

        if (originalDescriptor) {
            Object.defineProperty(HTMLInputElement.prototype, 'value', originalDescriptor);
        }
    });
});