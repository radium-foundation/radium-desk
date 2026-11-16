import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

describe('extension package layout (IT ZIP)', () => {
    it('manifest references files that exist in the extension tree', () => {
        const manifest = JSON.parse(readFileSync(path.join(root, 'manifest.json'), 'utf8'));

        expect(manifest.background?.service_worker).toBe('src/background.js');
        expect(existsSync(path.join(root, manifest.background.service_worker))).toBe(true);

        const scriptPaths = manifest.content_scripts?.flatMap((cs) => cs.js ?? []) ?? [];
        expect(scriptPaths.length).toBeGreaterThan(0);

        for (const rel of scriptPaths) {
            expect(existsSync(path.join(root, rel)), `missing content script ${rel}`).toBe(true);
        }
    });
});