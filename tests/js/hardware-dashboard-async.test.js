import { describe, expect, it } from 'vitest';

describe('hardware dashboard progressive contract', () => {
    it('documents progressive query parameters for the async client', () => {
        const url = new URL('https://desk.example/dashboard/hardware/rows');
        url.searchParams.set('hw_progressive', '1');
        url.searchParams.set('hw_page', '1');
        url.searchParams.set('hw_filter', 'needs_action');

        expect(url.searchParams.get('hw_progressive')).toBe('1');
        expect(url.searchParams.get('hw_page')).toBe('1');
    });
});
