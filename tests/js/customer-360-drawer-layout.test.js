import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const appCssPath = join(
    dirname(fileURLToPath(import.meta.url)),
    '../../resources/css/app.css',
);

describe('customer 360 drawer layout', () => {
    it('anchors drawer shell below the shared topbar token', () => {
        const css = readFileSync(appCssPath, 'utf8');
        const drawerRule = css.match(/\.customer-360-drawer\s*\{([^}]+)\}/s)?.[1] ?? '';

        expect(drawerRule).toContain('top: var(--topbar-height)');
        expect(drawerRule).not.toMatch(/\binset:\s*0\b/);
    });
});