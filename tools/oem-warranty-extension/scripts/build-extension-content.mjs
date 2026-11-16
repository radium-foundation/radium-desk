#!/usr/bin/env node
import * as esbuild from 'esbuild';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const entry = path.join(root, 'src/content/mantra-fill.js');
const outfile = path.join(root, 'src/content/mantra-fill.bundle.js');

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