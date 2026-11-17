import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

// src/main/index.js resolves the bundled app from import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH.
// If Vite inlines `undefined`, the packaged app crashes on launch (ERR_INVALID_ARG_TYPE).
test('production main bundle embeds a defined build path', () => {
    const root = fileURLToPath(new URL('..', import.meta.url));
    // Run the local CLI with Node directly: `npx` is a .cmd shim on Windows.
    const cli = join(root, 'node_modules', 'electron-vite', 'bin', 'electron-vite.js');
    execFileSync(process.execPath, [cli, 'build'], { cwd: root, stdio: 'pipe' });
    const bundle = readFileSync(join(root, 'out', 'main', 'index.js'), 'utf8');
    assert.match(bundle, /\.\.\/\.\.\/\.\.\/build/);
    assert.doesNotMatch(bundle, /import\.meta\.dirname, void 0/);
});
