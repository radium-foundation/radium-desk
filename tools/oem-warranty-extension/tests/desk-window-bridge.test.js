import { describe, expect, it } from 'vitest';
import { DESK_WINDOW_MSG } from '../src/lib/desk-window-bridge.js';

describe('desk window bridge', () => {
    it('uses stable postMessage type identifiers', () => {
        expect(DESK_WINDOW_MSG.HANDOFF_REQUEST).toBe('RADESK_OEM_HANDOFF_REQUEST');
        expect(DESK_WINDOW_MSG.SESSION_READY).toBe('RADESK_OEM_HANDOFF_SESSION_READY');
        expect(DESK_WINDOW_MSG.HANDOFF_STATUS).toBe('RADESK_OEM_HANDOFF_STATUS');
    });
});