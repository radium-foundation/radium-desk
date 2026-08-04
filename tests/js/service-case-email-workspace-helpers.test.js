import { describe, expect, it } from 'vitest';
import {
    canStartSend,
    isNearBottom,
    newestCursorFromMessages,
    oldestCursorFromMessages,
    preferDefaultSubject,
    textToHtml,
} from '../../resources/js/service-case-email-workspace-helpers.js';

describe('service-case-email-workspace helpers', () => {
    it('prefills subject unless the user edited it', () => {
        expect(preferDefaultSubject('', 'Re: Scanner', false)).toBe('Re: Scanner');
        expect(preferDefaultSubject('My subject', 'Re: Scanner', true)).toBe('My subject');
    });

    it('locks duplicate sends while sending or empty', () => {
        expect(canStartSend({ sending: true, body: 'Hello' })).toBe(false);
        expect(canStartSend({ sending: false, body: '  ' })).toBe(false);
        expect(canStartSend({ sending: false, body: 'Hello' })).toBe(true);
    });

    it('detects near-bottom scroll for live refresh auto-scroll', () => {
        const atBottom = {
            scrollHeight: 500,
            scrollTop: 450,
            clientHeight: 40,
        };
        const mid = {
            scrollHeight: 500,
            scrollTop: 100,
            clientHeight: 40,
        };

        expect(isNearBottom(atBottom)).toBe(true);
        expect(isNearBottom(mid)).toBe(false);
    });

    it('builds older/newer cursors from message pages', () => {
        const messages = [
            { id: 1, direction: 'inbound', occurred_at: '2026-08-01T10:00:00Z' },
            { id: 2, direction: 'outbound', occurred_at: '2026-08-01T11:00:00Z' },
        ];

        expect(oldestCursorFromMessages(messages)).toEqual({
            before_at: '2026-08-01T10:00:00Z',
            before_id: 1,
            before_direction: 'inbound',
        });
        expect(newestCursorFromMessages(messages)).toEqual({
            since_at: '2026-08-01T11:00:00Z',
            since_id: 2,
            since_direction: 'outbound',
        });
    });

    it('converts plain text drafts to paragraph html', () => {
        expect(textToHtml('Hello\n\nWorld')).toContain('<p>Hello</p>');
        expect(textToHtml('A\nB')).toContain('<br>');
    });
});
