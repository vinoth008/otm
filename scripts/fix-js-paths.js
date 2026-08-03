/**
 * Fix broken script src paths in frontend/html/*.html and subfolders.
 *
 * Correct location of JS files: frontend/js/*.js
 *   - Top-level page  (frontend/html/x.html)         -> ../js/xxx.js
 *   - Role page       (frontend/html/<role>/x.html)  -> ../../js/xxx.js
 *
 * This script rewrites every <script src="..."> to the correct relative path
 * and ensures utils.js is loaded before app_common.js.
 */
const fs = require('fs');
const path = require('path');

const HTML_DIR = path.join(__dirname, '..', 'frontend', 'html');
const JS_DIRNAME = 'js';

function walk(dir) {
    let results = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            results = results.concat(walk(full));
        } else if (entry.isFile() && entry.name.endsWith('.html')) {
            results.push(full);
        }
    }
    return results;
}

function correctSrc(src, prefix) {
    // Only touch script srcs that point into the js folder
    const base = src.replace(/^\.*\//g, '');
    if (!base.startsWith(JS_DIRNAME + '/')) return src;
    const file = base.slice(JS_DIRNAME.length + 1);
    return prefix + JS_DIRNAME + '/' + file;
}

const files = walk(HTML_DIR);
let changedCount = 0;
let needsUtils = 0;

for (const file of files) {
    const rel = path.relative(HTML_DIR, file);
    const isDeep = rel.includes(path.sep); // in a role subfolder
    const prefix = isDeep ? '../../' : '../';

    let html = fs.readFileSync(file, 'utf8');
    const original = html;

    // 1. Fix all script src paths
    html = html.replace(/<script\s+src="([^"]+)"\s*>/g, (m, src) => {
        const fixed = correctSrc(src, prefix);
        return fixed === src ? m : '<script src="' + fixed + '">';
    });

    // 2. Ensure utils.js is loaded before app_common.js
    const appCommonIdx = html.indexOf('app_common.js');
    const utilsIdx = html.indexOf('utils.js');
    if (appCommonIdx !== -1 && utilsIdx === -1) {
        const insertAt = html.lastIndexOf('<script', appCommonIdx);
        const utilsTag = '<script src="' + prefix + 'js/utils.js"></script>\n    ';
        html = html.slice(0, insertAt) + utilsTag + html.slice(insertAt);
        needsUtils++;
    }

    if (html !== original) {
        fs.writeFileSync(file, html, 'utf8');
        changedCount++;
        console.log('[FIXED] ' + rel);
    } else {
        console.log('[OK]    ' + rel);
    }
}

console.log('\nDone. Fixed ' + changedCount + ' file(s), added utils.js to ' + needsUtils + ' file(s).');