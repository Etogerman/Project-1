import assert from 'node:assert/strict';
import test from 'node:test';

import { createServer } from 'vite';

let viteServer;

test.before(async () => {
    viteServer = await createServer({
        appType: 'custom',
        logLevel: 'error',
        optimizeDeps: {
            entries: [],
            noDiscovery: true,
        },
        server: {
            middlewareMode: true,
        },
    });
});

test.after(async () => {
    await viteServer?.close();
});

test('keeps legacy contact phone write action as legacy payload', async () => {
    const { normalizeActionItemForType } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const normalized = normalizeActionItemForType({
        type: 'write_contact_field',
        source_type: 'static_value',
        static_value: '+7 999 111-22-33',
        target_scope: 'contact',
        target_field: 'phone',
    });

    assert.equal(normalized.type, 'write_contact_field');
    assert.equal(normalized.source_type, 'static_value');
    assert.equal(normalized.static_value, '+7 999 111-22-33');
    assert.equal(normalized.target_scope, 'contact');
    assert.equal(normalized.target_field, 'phone');
    assert.equal(normalized.value_source, undefined);
    assert.equal(normalized.manual_value, undefined);
});
