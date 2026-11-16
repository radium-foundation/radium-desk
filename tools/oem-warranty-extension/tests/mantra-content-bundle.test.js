import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const bundlePath = path.join(root, 'src/content/mantra-fill.bundle.js');
const manifestPath = path.join(root, 'manifest.json');

describe('mantra content bundle (Chrome classic script)', () => {
    it('manifest references the bundled Mantra script without module type', () => {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        const mantra = manifest.content_scripts.find((cs) =>
            cs.matches?.some((m) => m.includes('mantratecapp.com')),
        );

        expect(mantra).toBeDefined();
        expect(mantra.js).toEqual(['src/content/mantra-fill.bundle.js']);
        expect(mantra.type).toBeUndefined();
    });

    it('bundle exists and has no top-level ES module import/export statements', () => {
        const source = readFileSync(bundlePath, 'utf8');

        expect(source.length).toBeGreaterThan(500);
        expect(source).toMatch(/populateMantraSerialField|claimHandoffWithRetry/);
        expect(source).not.toMatch(/^\s*import\s+/m);
        expect(source).not.toMatch(/^\s*export\s+/m);
    });
});