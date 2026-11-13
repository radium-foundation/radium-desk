import { describe, expect, it } from 'vitest';
import { isAllowedDeskSenderUrl } from '../src/lib/origin-guard.js';

describe('isAllowedDeskSenderUrl', () => {
    it('allows production desk', () => {
        expect(isAllowedDeskSenderUrl('https://desk.radiumbox.com/dashboard')).toBe(true);
    });

    it('rejects arbitrary origins', () => {
        expect(isAllowedDeskSenderUrl('https://evil.example/')).toBe(false);
    });

    it('allows local fixture hosts', () => {
        expect(isAllowedDeskSenderUrl('http://127.0.0.1:4173/fixture')).toBe(true);
    });
});
