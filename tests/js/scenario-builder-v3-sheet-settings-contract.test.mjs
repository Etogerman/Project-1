import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

test('sheet settings dialog keeps guarded delete action in the active sheet parameters', () => {
    const source = readFileSync(join(process.cwd(), 'resources/js/scenario-builder-v3/App.jsx'), 'utf8');

    assert.match(source, /function openSheetSettings/);
    assert.match(source, /title="Параметры листа"/);
    assert.match(source, /data-sheet-settings/);
    assert.match(source, /Параметры листа/);
    assert.match(source, /Удалить лист/);
    assert.match(source, /Главный лист удалить нельзя/);
    assert.match(source, /setIsConfirmingDelete/);
    assert.doesNotMatch(source, /title="Переименовать лист"/);
});
