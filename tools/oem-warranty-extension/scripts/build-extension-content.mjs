#!/usr/bin/env node
import * as esbuild from 'esbuild';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

/** @type {Array<{ entry: string, outfile: string }>} */
const bundles = [
    {
        entry: path.join(root, 'src/content/mantra-fill.js'),
        outfile: path.join(root, 'src/content/mantra-fill.bundle.js'),
    },
    {
        entry: path.join(root, 'src/content/morpho-fill.js'),
        outfile: path.join(root, 'src/content/morpho-fill.bundle.js'),
    },
];

for (const { entry, outfile } of bundles) {
    await esbuild.build({
        entryPoints: [entry],
        bundle: true,
        format: 'iife',
        platform: 'browser',
        target: 'chrome109',
        outfile,
        sourcemap: false,
        logLevel: 'info',
    });

    console.log(`Wrote ${path.relative(root, outfile)}`);
}