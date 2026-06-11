#!/usr/bin/env node
/**
 * Copies vendored Cap distribution files into assets/ so the plugin can ship
 * a fully self-hosted copy — no jsdelivr requests at runtime.
 *
 * Run via `bun run build` (or `npm run build`).
 */

import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(__dirname, '..');

const widgetPkg = resolve(projectRoot, 'node_modules/cap-widget');
const wasmPkg = resolve(projectRoot, 'node_modules/@cap.js/wasm');

const jsDestDir = resolve(projectRoot, 'assets/js/vendor');
const wasmDestDir = resolve(projectRoot, 'assets/wasm');

for (const [name, dir] of [['cap-widget', widgetPkg], ['@cap.js/wasm', wasmPkg]]) {
    if (!existsSync(dir)) {
        console.error(`${name} is not installed. Run \`bun install\` before building assets.`);
        process.exit(1);
    }
}

const widgetVersion = JSON.parse(
    readFileSync(resolve(widgetPkg, 'package.json'), 'utf8'),
).version;
const wasmVersion = JSON.parse(
    readFileSync(resolve(wasmPkg, 'package.json'), 'utf8'),
).version;

/** @type {{ srcDir: string, src: string, destDir: string, dest: string }[]} */
const files = [
    { srcDir: widgetPkg, src: 'cap.min.js', destDir: jsDestDir, dest: 'cap-widget.js' },
    { srcDir: widgetPkg, src: 'cap.compat.min.js', destDir: jsDestDir, dest: 'cap-widget.compat.js' },
    { srcDir: widgetPkg, src: 'cap-floating.min.js', destDir: jsDestDir, dest: 'cap-widget.floating.js' },
    { srcDir: widgetPkg, src: 'wasm-hashes.min.js', destDir: jsDestDir, dest: 'cap-widget.wasm-hashes.js' },
    { srcDir: wasmPkg, src: 'browser/cap_wasm_bg.wasm', destDir: wasmDestDir, dest: 'cap_wasm_bg.wasm' },
];

mkdirSync(jsDestDir, { recursive: true });
mkdirSync(wasmDestDir, { recursive: true });

const checkOnly = process.argv.includes('--check');
let mismatched = false;

for (const { srcDir, src, destDir, dest } of files) {
    const srcPath = resolve(srcDir, src);
    const destPath = resolve(destDir, dest);
    const relDest = destPath.slice(projectRoot.length + 1);

    if (!existsSync(srcPath)) {
        console.warn(`skip: ${src} not present in ${srcDir}`);
        continue;
    }

    if (checkOnly) {
        if (
            !existsSync(destPath) ||
            hash(readFileSync(srcPath)) !== hash(readFileSync(destPath))
        ) {
            console.error(`out of date: ${relDest}`);
            mismatched = true;
        }
        continue;
    }

    copyFileSync(srcPath, destPath);
    console.log(`wrote ${relDest}`);
}

if (checkOnly) {
    if (mismatched) {
        console.error('Run `bun run build` to refresh vendored assets.');
        process.exit(1);
    }
    console.log('Vendored Cap assets are up to date.');
    process.exit(0);
}

console.log(`Bundled cap-widget@${widgetVersion} + @cap.js/wasm@${wasmVersion}.`);

function hash(buffer) {
    return createHash('sha256').update(buffer).digest('hex');
}
