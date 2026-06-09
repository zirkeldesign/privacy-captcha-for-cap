#!/usr/bin/env node
/**
 * Copies the cap-widget distribution files from node_modules into
 * assets/js/vendor/ so the plugin can ship a self-hosted copy.
 *
 * Run via `npm run build`.
 */

import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(__dirname, '..');
const widgetPkg = resolve(projectRoot, 'node_modules/cap-widget');
const destDir = resolve(projectRoot, 'assets/js/vendor');

if (!existsSync(widgetPkg)) {
    console.error(
        'cap-widget is not installed. Run `npm install` before building assets.',
    );
    process.exit(1);
}

const widgetVersion = JSON.parse(
    readFileSync(resolve(widgetPkg, 'package.json'), 'utf8'),
).version;

const files = [
    { src: 'cap.min.js', dest: 'cap-widget.js' },
    { src: 'cap.compat.min.js', dest: 'cap-widget.compat.js' },
    { src: 'cap-floating.min.js', dest: 'cap-widget.floating.js' },
    { src: 'wasm-hashes.min.js', dest: 'cap-widget.wasm-hashes.js' },
];

mkdirSync(destDir, { recursive: true });

const checkOnly = process.argv.includes('--check');
let mismatched = false;

for (const { src, dest } of files) {
    const srcPath = resolve(widgetPkg, src);
    const destPath = resolve(destDir, dest);

    if (!existsSync(srcPath)) {
        console.warn(`skip: ${src} not present in cap-widget package`);
        continue;
    }

    if (checkOnly) {
        if (
            !existsSync(destPath) ||
            hash(readFileSync(srcPath)) !== hash(readFileSync(destPath))
        ) {
            console.error(`out of date: assets/js/vendor/${dest}`);
            mismatched = true;
        }
        continue;
    }

    copyFileSync(srcPath, destPath);
    console.log(`wrote assets/js/vendor/${dest}`);
}

if (checkOnly) {
    if (mismatched) {
        console.error('Run `npm run build` to refresh vendored assets.');
        process.exit(1);
    }
    console.log('Vendored cap-widget assets are up to date.');
    process.exit(0);
}

console.log(`Bundled cap-widget@${widgetVersion}.`);

function hash(buffer) {
    return createHash('sha256').update(buffer).digest('hex');
}
