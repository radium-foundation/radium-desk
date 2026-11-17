import { describe, expect, it, vi } from 'vitest';
import {
    copyMantraSerialToClipboard,
    findMantraCaptchaField,
    focusMantraCaptchaField,
    highlightMantraCaptchaField,
    MANTRA_CAPTCHA_HIGHLIGHT_CLASS,
    watchMantraCaptchaEntry,
} from '../src/lib/mantra-operator-assist.js';

describe('mantra-operator-assist', () => {
    it('finds txtCaptcha and does not modify captcha value', () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" value="" />';
        const field = findMantraCaptchaField(document);
        expect(field?.id).toBe('txtCaptcha');
        field.value = 'leave-operator';
        expect(field.value).toBe('leave-operator');
    });

    it('focuses captcha field after serial fill', () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" />';
        const field = document.getElementById('txtCaptcha');
        field.focus = vi.fn();
        field.scrollIntoView = vi.fn();

        const result = focusMantraCaptchaField(document);
        expect(result.focused).toBe(true);
        expect(field.focus).toHaveBeenCalled();
    });

    it('highlights captcha field for visibility', () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" />';
        highlightMantraCaptchaField(document);
        expect(document.getElementById('txtCaptcha').classList.contains(MANTRA_CAPTCHA_HIGHLIGHT_CLASS)).toBe(true);
    });

    it('detects operator captcha entry without reading challenge image', () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" />';
        const onChange = vi.fn();
        watchMantraCaptchaEntry(document, onChange);
        const field = document.getElementById('txtCaptcha');
        field.value = 'x';
        field.dispatchEvent(new Event('input', { bubbles: true }));
        expect(onChange).toHaveBeenCalledWith(true);
    });

    it('does not report whitespace-only as entered', () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" />';
        const onChange = vi.fn();
        watchMantraCaptchaEntry(document, onChange);
        onChange.mockClear();
        const field = document.getElementById('txtCaptcha');
        field.value = '   ';
        field.dispatchEvent(new Event('input', { bubbles: true }));
        expect(onChange).toHaveBeenLastCalledWith(false);
    });

    it('detects paste into captcha field', () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" />';
        const onChange = vi.fn();
        watchMantraCaptchaEntry(document, onChange);
        onChange.mockClear();
        const field = document.getElementById('txtCaptcha');
        field.value = 'abcd';
        field.dispatchEvent(new Event('paste', { bubbles: true }));
        expect(onChange).toHaveBeenCalledWith(true);
    });

    it('binds when captcha field is inserted after serial fill', async () => {
        document.body.innerHTML = '<motion-root></motion-root>';
        const onChange = vi.fn();
        const stop = watchMantraCaptchaEntry(document, onChange);
        onChange.mockClear();

        const input = document.createElement('input');
        input.id = 'txtCaptcha';
        input.type = 'text';
        document.body.appendChild(input);
        await new Promise((r) => requestAnimationFrame(r));

        input.value = 'ok';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        expect(onChange).toHaveBeenCalledWith(true);
        stop();
    });

    it('re-binds when captcha input node is replaced', async () => {
        document.body.innerHTML = '<input id="txtCaptcha" type="text" />';
        const onChange = vi.fn();
        const stop = watchMantraCaptchaEntry(document, onChange);
        onChange.mockClear();

        const replacement = document.createElement('input');
        replacement.id = 'txtCaptcha';
        replacement.type = 'text';
        document.getElementById('txtCaptcha').replaceWith(replacement);
        await new Promise((r) => requestAnimationFrame(r));

        replacement.value = 'new';
        replacement.dispatchEvent(new Event('input', { bubbles: true }));
        expect(onChange).toHaveBeenCalledWith(true);
        stop();
    });

    it('copies serial on explicit clipboard call', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        const result = await copyMantraSerialToClipboard('syn123');
        expect(result).toEqual({ ok: true });
        expect(writeText).toHaveBeenCalledWith('SYN123');
    });
});