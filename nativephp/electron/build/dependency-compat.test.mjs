import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const lock = JSON.parse(readFileSync(new URL('../package-lock.json', import.meta.url), 'utf8'));

function installedMinimatch(name) {
    const path = require.resolve(name + '/package.json');
    const fromPackage = createRequire(path);
    return fromPackage.resolve('minimatch');
}

test('the vulnerable minimatch range is absent from the release build dependency lock', () => {
    const paths = Object.entries(lock.packages).filter(([name]) => /(?:^|\/)node_modules\/minimatch$/.test(name));
    assert.ok(paths.length > 0);
    for (const [path, pkg] of paths) {
        const [major, minor, patch] = pkg.version.split('.').map(Number);
        assert.ok(!(major >= 7 && (major < 11 || (major === 11 && minor === 0 && patch < 1))),
            'GHSA-g3hv-75wg-m249 vulnerable version at ' + path + ': ' + pkg.version);
    }
});

test('the build tooling resolves a patched minimatch for modern consumers', () => {
    const modern = ['app-builder-lib', '@typescript-eslint/typescript-estree', '@electron/universal'];
    for (const name of modern) {
        const resolved = installedMinimatch(name);
        assert.ok(resolved.includes('node_modules/minimatch/'), name + ': ' + resolved);
        const source = createRequire(require.resolve(name + '/package.json'))('minimatch');
        assert.equal(typeof source.minimatch, 'function', name);
    }
});

test('legacy glob consumers retain compatible minimatch API', () => {
    const glob = require('glob');
    assert.ok(glob.sync('*.json', { cwd: process.cwd() }).includes('package.json'));
    const compare = require('dir-compare').compareSync('build', 'build', { excludeFilter: 'not-present' });
    assert.equal(compare.same, true);
});
