#!/usr/bin/env node
// Translation tooling for the panel's interface. Run from the repository root.
//
//   node scripts/i18n.mjs check          every language file is sound (see below); exit 1 if not
//   node scripts/i18n.mjs check --strict ... and no language is missing a sentence
//   node scripts/i18n.mjs sync           write lang/en.json from the sentences found in the code
//   node scripts/i18n.mjs todo <code>    the sentences <code> still lacks, as JSON to fill in
//   node scripts/i18n.mjs stats          how complete each language is
//
// Sentences are found where the code writes them as a string literal:
//   React:  t('...')  tn('...', n)  <Trans text="..." />
//   PHP:    __('...')
// so always pass a literal. A call with anything else as the key is reported, because it cannot
// be found, and so can never be translated.
import {
    readdirSync,
    readFileSync,
    statSync,
    writeFileSync,
    existsSync,
} from 'node:fs';
import { join, extname, basename } from 'node:path';

const root = process.cwd();
const LANG = join(root, 'lang');

function walk(dir, exts, out = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        const stat = statSync(path);
        if (stat.isDirectory()) {
            if (
                [
                    'node_modules',
                    'vendor',
                    'actions',
                    'routes',
                    'wayfinder',
                ].includes(name)
            )
                continue;
            walk(path, exts, out);
        } else if (exts.includes(extname(name))) {
            out.push(path);
        }
    }
    return out;
}

function unescape(raw, quote, language) {
    let out = '';
    for (let i = 0; i < raw.length; i++) {
        const c = raw[i];
        if (c !== '\\') {
            out += c;
            continue;
        }
        const n = raw[++i];
        if (language === 'php' && quote === "'") {
            out += n === "'" || n === '\\' ? n : `\\${n}`;
        } else if (n === 'n') out += '\n';
        else if (n === 't') out += '\t';
        else if (n === 'u') {
            out += String.fromCharCode(parseInt(raw.slice(i + 1, i + 5), 16));
            i += 4;
        } else out += n;
    }
    return out;
}

const found = new Map(); // sentence -> [files]
const problems = [];

function add(key, file) {
    if (!found.has(key)) found.set(key, []);
    found.get(key).push(file);
}

const LITERAL = String.raw`(['"\x60])((?:\\.|(?!\1)[^\\])*)\1`;

function scanJs(file, text) {
    const rel = file.slice(root.length + 1);
    // t('..') / tn('..', n): the key is a literal and is followed by "," or ")".
    for (const m of text.matchAll(
        new RegExp(
            String.raw`(?<![.\w$])(?:t|tn)\(\s*${LITERAL}\s*([,)])`,
            'g',
        ),
    )) {
        if (m[1] === '`' && m[2].includes('${')) {
            problems.push(
                `${rel}: a template with \${} cannot be a key: ${m[2].slice(0, 60)}`,
            );
            continue;
        }
        add(unescape(m[2], m[1], 'js'), rel);
    }
    // a call whose first argument is not a literal
    for (const m of text.matchAll(
        /(?<![.\w$])(?:t|tn)\(\s*([^'"`\s)][^,)]{0,40})/g,
    )) {
        const before = text.slice(Math.max(0, m.index - 20), m.index);
        if (/(?:function|=>|const|let|var|import|type|:)\s*$/.test(before))
            continue;
        if (/^(?:key|string|params)\b/.test(m[1])) continue;
        problems.push(
            `${rel}: t() needs a string literal, found: t(${m[1].trim()}…`,
        );
    }
    for (const m of text.matchAll(
        /<Trans\s+text=(?:"((?:[^"\\]|\\.)*)"|\{\s*(['"`])((?:\\.|(?!\2)[^\\])*)\2\s*\})/g,
    )) {
        add(
            m[1] !== undefined
                ? unescape(m[1], '"', 'js')
                : unescape(m[3], m[2], 'js'),
            rel,
        );
    }
    if (/<Trans\b(?!\s+text=)/.test(text.replace(/<Trans\s+text=/g, ''))) {
        problems.push(
            `${rel}: <Trans> must have text="…" as its first attribute`,
        );
    }
}

function scanPhp(file, text) {
    const rel = file.slice(root.length + 1);
    for (const m of text.matchAll(
        new RegExp(String.raw`(?<![\w>$:])__\(\s*${LITERAL}\s*([,)])`, 'g'),
    )) {
        if (m[1] === '`') continue;
        add(unescape(m[2], m[1], 'php'), rel);
    }
    for (const m of text.matchAll(
        /(?<![\w>$:])__\(\s*([^'"\s)][^,)]{0,40})/g,
    )) {
        problems.push(
            `${rel}: __() needs a string literal, found: __(${m[1].trim()}…`,
        );
    }
}

function collect() {
    found.clear();
    problems.length = 0;
    for (const f of walk(join(root, 'resources/js'), ['.ts', '.tsx'])) {
        if (
            f.endsWith('lib/i18n.ts') ||
            f.endsWith('lib/trans.tsx') ||
            f.endsWith('hooks/use-t.ts')
        )
            continue;
        scanJs(f, readFileSync(f, 'utf8'));
    }
    for (const dir of ['app', 'routes', 'config', 'resources/views']) {
        if (!existsSync(join(root, dir))) continue;
        for (const f of walk(join(root, dir), ['.php']))
            scanPhp(f, readFileSync(f, 'utf8'));
    }
    return [...found.keys()].sort((a, b) => a.localeCompare(b, 'en'));
}

const placeholders = (s) =>
    [...new Set(s.match(/:[a-zA-Z_][a-zA-Z0-9_]*/g) ?? [])].sort((a, b) =>
        a.localeCompare(b),
    );
const tags = (s) =>
    [...(s.match(/<\/?\w+\s*\/?>/g) ?? [])].sort((a, b) => a.localeCompare(b));

function plurals(s) {
    return s.includes('|') && /^[a-z]+:/.test(s)
        ? s.split('|').map((p) => p.split(':')[0])
        : null;
}

const CATEGORIES = new Set(['zero', 'one', 'two', 'few', 'many', 'other']);

function locales() {
    return readdirSync(LANG)
        .filter((f) => f.endsWith('.json') && f !== 'en.json')
        .map((f) => basename(f, '.json'))
        .sort();
}

function configured() {
    const text = readFileSync(join(root, 'config/commypanel.php'), 'utf8');
    const block = text.match(/'locales'\s*=>\s*\[([\s\S]*?)\]/)?.[1] ?? '';
    return [...block.matchAll(/'([a-z]{2,3})'\s*=>/g)].map((m) => m[1]);
}

function load(code) {
    return JSON.parse(readFileSync(join(LANG, `${code}.json`), 'utf8'));
}

function check(strict) {
    const keys = collect();
    const errors = [...problems];
    const warnings = [];
    const listed = configured();

    for (const code of locales())
        if (!listed.includes(code))
            errors.push(
                `lang/${code}.json exists but ${code} is not in config/commypanel.php 'locales'`,
            );
    for (const code of listed)
        if (code !== 'en' && !existsSync(join(LANG, `${code}.json`)))
            errors.push(
                `${code} is in config/commypanel.php 'locales' but lang/${code}.json is missing`,
            );

    const known = new Set(keys);
    for (const code of locales()) {
        let data;
        try {
            data = load(code);
        } catch (e) {
            errors.push(`lang/${code}.json is not valid JSON: ${e.message}`);
            continue;
        }
        let missing = 0;
        for (const key of keys) if (!(key in data)) missing++;
        for (const [key, value] of Object.entries(data)) {
            if (!known.has(key)) {
                warnings.push(
                    `lang/${code}.json: "${key.slice(0, 60)}" is not used by the code any more`,
                );
                continue;
            }
            if (typeof value !== 'string' || value.trim() === '') {
                errors.push(
                    `lang/${code}.json: empty translation for "${key.slice(0, 60)}"`,
                );
                continue;
            }
            if (placeholders(value).join() !== placeholders(key).join())
                errors.push(
                    `lang/${code}.json: placeholders differ in "${key.slice(0, 60)}" (${placeholders(key).join(' ') || 'none'} vs ${placeholders(value).join(' ') || 'none'})`,
                );
            if (tags(value).join() !== tags(key).join())
                errors.push(
                    `lang/${code}.json: tags differ in "${key.slice(0, 60)}" (${tags(key).join(' ') || 'none'} vs ${tags(value).join(' ') || 'none'})`,
                );
            const keyForms = plurals(key);
            if (keyForms) {
                const forms = plurals(value);
                if (
                    !forms ||
                    forms.some((f) => !CATEGORIES.has(f)) ||
                    !forms.includes('other')
                )
                    errors.push(
                        `lang/${code}.json: the plural forms of "${key.slice(0, 60)}" must be like one:…|other:…`,
                    );
            }
        }
        const done = keys.length - missing;
        console.log(
            `${code}: ${done}/${keys.length} sentences (${keys.length ? Math.round((done / keys.length) * 100) : 100}%)`,
        );
        if (strict && missing > 0)
            errors.push(
                `lang/${code}.json is missing ${missing} sentence(s); run: node scripts/i18n.mjs todo ${code}`,
            );
    }

    for (const w of warnings.slice(0, 20)) console.log(`warning: ${w}`);
    if (warnings.length > 20)
        console.log(
            `warning: ... and ${warnings.length - 20} more unused sentences`,
        );
    for (const e of errors) console.error(`error: ${e}`);
    console.log(
        `${keys.length} sentences in the code, ${errors.length} error(s)`,
    );
    process.exit(errors.length ? 1 : 0);
}

function sync() {
    const keys = collect();
    for (const p of problems) console.error(`error: ${p}`);
    const out = {};
    for (const key of keys) out[key] = key;
    writeFileSync(join(LANG, 'en.json'), `${JSON.stringify(out, null, 4)}\n`);
    console.log(`lang/en.json: ${keys.length} sentences`);
    process.exit(problems.length ? 1 : 0);
}

function todo(code) {
    const keys = collect();
    const data = existsSync(join(LANG, `${code}.json`)) ? load(code) : {};
    const out = {};
    for (const key of keys) if (!(key in data)) out[key] = '';
    console.log(JSON.stringify(out, null, 4));
}

function stats() {
    const keys = collect();
    console.log(`${keys.length} sentences`);
    for (const code of locales()) {
        const data = load(code);
        console.log(
            `${code}: ${keys.filter((k) => k in data).length}/${keys.length}`,
        );
    }
}

const [command, arg] = process.argv.slice(2);
if (command === 'check') check(arg === '--strict');
else if (command === 'sync') sync();
else if (command === 'todo' && arg) todo(arg);
else if (command === 'stats') stats();
else {
    console.error(
        'usage: node scripts/i18n.mjs check [--strict] | sync | todo <code> | stats',
    );
    process.exit(2);
}
