#!/usr/bin/env node
/**
 * Compares the Cap packages vendored into assets/ with what upstream ships
 * now, and reports the changes that break or may break the plugin.
 *
 * Run weekly by .github/workflows/upstream-watch.yml, which turns the report
 * into a GitHub issue. Run locally with `bun run upstream:watch`.
 *
 * Why this exists: Cap Standalone 3.1 switched new site keys to the `hashwx`
 * protocol. The vendored widget did not know it, and every form on such a key
 * failed with "unsupported format-2 protocol 'hashwx'". Nothing in CI noticed,
 * because the plugin itself had not changed. The checks below look for that
 * class of drift:
 *
 * - breaking: the Cap server can issue a protocol the vendored widget cannot
 *   solve, or its default protocol for new keys is one of those.
 * - breaking: the latest widget fetches an asset from jsdelivr that the
 *   vendored one did not. The build strips those URLs, so an unvendored asset
 *   simply fails to load.
 * - notice: a new widget or WASM release, new `window.CAP_*` globals, or new
 *   files in the WASM package.
 *
 * Writes the report as Markdown to the path in REPORT_FILE (default
 * upstream-report.md) and exits 0 either way. Set VENDORED_WIDGET or
 * VENDORED_WASM to test against a different baseline than bun.lock.
 */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync, appendFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const serverSourceUrl = 'https://raw.githubusercontent.com/tiagozip/cap/main/standalone/src/server.js';

const lock = readFileSync(resolve(projectRoot, 'bun.lock'), 'utf8');
const lockedVersion = (name) => {
    const match = lock.match(new RegExp(`"${name.replace('/', '\\/')}": \\["${name.replace('/', '\\/')}@([^"]+)"`));
    if (!match) {
        throw new Error(`${name} not found in bun.lock`);
    }

    return match[1];
};

const vendored = {
    widget: process.env.VENDORED_WIDGET || lockedVersion('cap-widget'),
    wasm: process.env.VENDORED_WASM || lockedVersion('@cap.js/wasm'),
};

const latest = {
    widget: await latestVersion('cap-widget'),
    wasm: await latestVersion('@cap.js/wasm'),
};

const workDir = mkdtempSync(join(tmpdir(), 'cap-upstream-'));
const breaking = [];
const notices = [];

try {
    const widgetOld = await unpack('cap-widget', vendored.widget);
    const widgetNew = await unpack('cap-widget', latest.widget);
    const wasmOld = await unpack('@cap.js/wasm', vendored.wasm);
    const wasmNew = await unpack('@cap.js/wasm', latest.wasm);

    const supported = widgetProtocols(widgetOld);
    const supportedByLatest = widgetProtocols(widgetNew);
    const server = await serverProtocols();

    const unsupported = server.accepted.filter((p) => !supported.includes(p));
    if (server.default && !supported.includes(server.default)) {
        breaking.push(
            `The Cap server creates new site keys with protocol \`${server.default}\`, which the vendored cap-widget ${vendored.widget} cannot solve. Every form on a new key fails.`
            + fixHint(server.default, supportedByLatest),
        );
    }
    for (const protocol of unsupported.filter((p) => p !== server.default)) {
        breaking.push(
            `The Cap server accepts protocol \`${protocol}\` for site keys, which the vendored cap-widget ${vendored.widget} cannot solve.`
            + fixHint(protocol, supportedByLatest),
        );
    }

    const newCdnAssets = difference(cdnAssets(widgetNew), cdnAssets(widgetOld));
    if (newCdnAssets.length) {
        breaking.push(
            `cap-widget ${latest.widget} loads ${list(newCdnAssets)} from jsdelivr, which ${vendored.widget} did not. The build strips CDN URLs, so each of these has to be vendored by \`scripts/build-assets.mjs\` and passed to the widget through its \`window.CAP_*\` URL global in \`Asset\\Enqueuer\`, or it will not load.`,
        );
    }

    if (latest.widget !== vendored.widget) {
        notices.push(`cap-widget ${vendored.widget} is vendored, ${latest.widget} is out. In 0.x any minor bump may break.`);
    }
    if (latest.wasm !== vendored.wasm) {
        notices.push(`@cap.js/wasm ${vendored.wasm} is vendored, ${latest.wasm} is out.`);
    }

    const newGlobals = difference(capGlobals(widgetNew), capGlobals(widgetOld));
    if (newGlobals.length) {
        notices.push(`cap-widget ${latest.widget} reads new globals: ${list(newGlobals)}. Check whether the plugin needs to set them.`);
    }

    const newWasmFiles = difference(browserFiles(wasmNew), browserFiles(wasmOld));
    if (newWasmFiles.length) {
        notices.push(`@cap.js/wasm ${latest.wasm} ships new browser files: ${list(newWasmFiles)}.`);
    }
} finally {
    rmSync(workDir, { recursive: true, force: true });
}

const report = render();
const reportFile = process.env.REPORT_FILE || 'upstream-report.md';
writeFileSync(reportFile, report);
console.log(report);

if (process.env.GITHUB_OUTPUT) {
    appendFileSync(process.env.GITHUB_OUTPUT, `findings=${breaking.length + notices.length > 0}\n`);
    appendFileSync(process.env.GITHUB_OUTPUT, `breaking=${breaking.length > 0}\n`);
}

async function latestVersion(name) {
    const response = await fetch(`https://registry.npmjs.org/${name}/latest`);
    if (!response.ok) {
        throw new Error(`npm registry answered ${response.status} for ${name}`);
    }

    return (await response.json()).version;
}

async function unpack(name, version) {
    const response = await fetch(`https://registry.npmjs.org/${name}/${version}`);
    if (!response.ok) {
        throw new Error(`npm registry answered ${response.status} for ${name}@${version}`);
    }
    const tarball = (await response.json()).dist.tarball;
    const archive = join(workDir, `${name.replace('/', '-')}-${version}.tgz`);
    writeFileSync(archive, Buffer.from(await (await fetch(tarball)).arrayBuffer()));

    const target = join(workDir, `${name.replace('/', '-')}-${version}`);
    execFileSync('mkdir', ['-p', target]);
    execFileSync('tar', ['xzf', archive, '-C', target]);

    return join(target, 'package');
}

/** Protocols the widget accepts, read from its format-2 whitelist. */
function widgetProtocols(pkg) {
    const source = readFileSync(join(pkg, 'src/cap.js'), 'utf8');

    return unique([...source.matchAll(/ch\??\.protocol === "([a-z0-9-]+)"/g)].map((m) => m[1]));
}

/** Protocols the Cap server can put on a site key, and its default for new keys. */
async function serverProtocols() {
    const response = await fetch(serverSourceUrl);
    if (!response.ok) {
        throw new Error(`GitHub answered ${response.status} for ${serverSourceUrl}`);
    }
    const source = await response.text();

    const defaults = source.match(/const keyDefaults = \{([\s\S]*?)\};/)?.[1] ?? '';
    const union = source.match(/protocol:\s*t\.Optional\(\s*t\.Union\(\[([\s\S]*?)\]\)/)?.[1] ?? '';
    const accepted = unique([...union.matchAll(/t\.Literal\("([^"]+)"\)/g)].map((m) => m[1]));

    if (!accepted.length) {
        breaking.push(`Could not read the accepted protocols from ${serverSourceUrl}. The file layout changed, so this watch has to be updated.`);
    }

    return { default: defaults.match(/protocol:\s*"([^"]+)"/)?.[1] ?? null, accepted };
}

function cdnAssets(pkg) {
    const bundle = readFileSync(join(pkg, 'cap.min.js'), 'utf8');

    return unique([...bundle.matchAll(/https:\/\/cdn\.jsdelivr\.net\/npm\/([^"'`]*)/g)]
        .map((m) => m[1].split('/').pop())
        .filter(Boolean));
}

function capGlobals(pkg) {
    const bundle = readFileSync(join(pkg, 'cap.min.js'), 'utf8');

    return unique([...bundle.matchAll(/\bCAP_[A-Z_]+\b/g)].map((m) => m[0]));
}

function browserFiles(pkg) {
    return readdirSync(join(pkg, 'browser'));
}

function fixHint(protocol, supportedByLatest) {
    return supportedByLatest.includes(protocol)
        ? ` cap-widget ${latest.widget} supports it: bump and rebuild.`
        : ` cap-widget ${latest.widget} does not support it either. Meanwhile, switch affected keys to a supported protocol through \`PUT /server/keys/:siteKey/config\`.`;
}

function render() {
    const lines = [
        `Vendored: cap-widget ${vendored.widget}, @cap.js/wasm ${vendored.wasm}. Latest: cap-widget ${latest.widget}, @cap.js/wasm ${latest.wasm}.`,
        '',
    ];
    if (!breaking.length && !notices.length) {
        lines.push('Nothing to do.');
    }
    if (breaking.length) {
        lines.push('## Breaking', '', ...breaking.map((b) => `- ${b}`), '');
    }
    if (notices.length) {
        lines.push('## Notices', '', ...notices.map((n) => `- ${n}`), '');
    }

    return lines.join('\n');
}

function difference(a, b) {
    return a.filter((x) => !b.includes(x));
}

function unique(items) {
    return [...new Set(items)].sort();
}

function list(items) {
    return items.map((i) => `\`${i}\``).join(', ');
}
