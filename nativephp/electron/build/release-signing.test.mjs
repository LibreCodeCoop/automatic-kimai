import { test } from 'node:test';
import assert from 'node:assert/strict';

test('unsigned release ignores blank certificate environment variables', async () => {
    const old = Object.fromEntries(['CSC_LINK','CSC_KEY_PASSWORD','WIN_CSC_LINK','WIN_CSC_KEY_PASSWORD'].map(k=>[k,process.env[k]]));
    try {
        for (const key of Object.keys(old)) process.env[key] = '';
        const config = (await import('../electron-builder.mjs?unsigned=1')).default;
        assert.equal(config.mac.identity, null);
        assert.equal(config.mac.hardenedRuntime, false);
        for (const key of Object.keys(old)) assert.equal(process.env[key], undefined);
    } finally {
        for (const [key,value] of Object.entries(old)) {
            if (value === undefined) delete process.env[key]; else process.env[key]=value;
        }
    }
});

test('configured certificate preserves the signed release path', async () => {
    const previous = process.env.CSC_LINK;
    try {
        process.env.CSC_LINK = 'synthetic-fake-cert.p12';
        const config = (await import('../electron-builder.mjs?signed=1')).default;
        assert.equal(config.mac.identity, undefined);
        assert.equal(config.mac.hardenedRuntime, true);
        assert.equal(process.env.CSC_LINK, 'synthetic-fake-cert.p12');
    } finally {
        if(previous===undefined) delete process.env.CSC_LINK;
        else process.env.CSC_LINK=previous;
    }
});
